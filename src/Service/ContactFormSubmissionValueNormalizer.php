<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;
use function is_array;
use function is_object;
use function is_scalar;

/**
 * Normalizes submitted form field values into strings for persistence.
 */
final readonly class ContactFormSubmissionValueNormalizer
{
    public function __construct(
        private ContactFormFileUploadHandlerInterface $fileUploadHandler,
    ) {
    }

    public function normalize(
        ContactFormField $field,
        mixed $value,
        ContactForm $form,
    ): string {
        return match ($field->getType()) {
            ContactFieldType::Checkbox => in_array($value, [true, '1', 1], true) ? '1' : '0',
            ContactFieldType::File     => $this->normalizeFileValue($value, $form, $field),
            ContactFieldType::Date     => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $this->scalarOrEmpty($value),
            ContactFieldType::Phone    => $this->normalizePhoneValue($value),
            default                    => $this->scalarOrEmpty($value),
        };
    }

    private function normalizePhoneValue(mixed $value): string
    {
        if (is_object($value) && method_exists($value, 'getE164')) {
            return (string) $value->getE164();
        }

        if (is_array($value)) {
            $prefix = (string) ($value['prefix'] ?? '');
            $number = (string) ($value['national_number'] ?? $value['number'] ?? '');

            if ($prefix !== '' && $number !== '') {
                return ContactPhoneValue::combine($prefix, $number);
            }
        }

        return $this->scalarOrEmpty($value);
    }

    private function normalizeFileValue(mixed $value, ContactForm $form, ContactFormField $field): string
    {
        if (!$value instanceof UploadedFile) {
            return '';
        }

        return $this->fileUploadHandler->upload($value, $form, $field);
    }

    private function scalarOrEmpty(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}
