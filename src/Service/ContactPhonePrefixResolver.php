<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;

/**
 * Resolves international dialing prefixes for Symfony phone fields.
 */
final class ContactPhonePrefixResolver
{
    /**
     * @param array<string, string> $defaultPrefixes map of dialing code to display label
     */
    public function __construct(
        private readonly array $defaultPrefixes,
    ) {
    }

    /**
     * @return array<string, string> choice labels keyed by dialing code
     */
    public function resolveForField(ContactFormField $field): array
    {
        if ($field->getType() !== ContactFieldType::Phone) {
            return [];
        }

        $phoneOptions = ContactFormFieldPhoneOptions::fromField($field);
        $configured   = $phoneOptions->prefixes;

        if ($configured === []) {
            return $this->defaultPrefixes;
        }

        $choices = [];

        foreach ($configured as $code) {
            $choices[$code] = $this->defaultPrefixes[$code] ?? $code;
        }

        return $choices !== [] ? $choices : $this->defaultPrefixes;
    }

    /**
     * @return list<string>
     */
    public function resolveCodesForField(ContactFormField $field): array
    {
        return array_keys($this->resolveForField($field));
    }
}
