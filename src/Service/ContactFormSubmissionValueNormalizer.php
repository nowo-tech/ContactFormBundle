<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeInterface;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use Nowo\ContactFormBundle\Upload\UploadedContactFileData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;
use function is_array;
use function is_object;
use function is_scalar;

/**
 * Normalizes submitted form field values into strings for persistence.
 *
 * File values (already validated by the public form) go to {@see ContactFormFileUploaderInterface} (wired by
 * {@see ContactFormFileUploaderPass}) as an {@see UploadedContactFile} VO; since 2.0 it is the only upload port.
 */
final readonly class ContactFormSubmissionValueNormalizer
{
    public function __construct(
        private ContactFormFileUploaderInterface $fileUploadHandler,
    ) {
    }

    /**
     * @param string $locale locale of the submission (1.8; given to {@see ContactFormFileTarget})
     */
    public function normalize(
        ContactFormField $field,
        mixed $value,
        ContactForm $form,
        string $locale = '',
    ): string {
        return match ($field->getType()) {
            ContactFieldType::Checkbox => in_array($value, [true, '1', 1], true) ? '1' : '0',
            ContactFieldType::File     => $this->normalizeFileValue($value, $form, $field, $locale),
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

    private function normalizeFileValue(mixed $value, ContactForm $form, ContactFormField $field, string $locale): string
    {
        if (!$value instanceof UploadedFile) {
            return '';
        }

        return $this->fileUploadHandler->store(
            UploadedContactFileData::fromUploadedFile($value),
            new ContactFormFileTarget(
                $form->getId(),
                $form->getSlug(),
                $field->getId(),
                $field->getName(),
                $locale,
                $field->getOptions() ?? [],
            ),
        );
    }

    private function scalarOrEmpty(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}
