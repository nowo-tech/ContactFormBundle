<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;

/**
 * Builds PhoneType options for public contact forms.
 */
final readonly class ContactPhoneInputOptionsResolver
{
    /**
     * @param array<string, mixed> $bundleDefaults
     */
    public function __construct(
        private array $bundleDefaults,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForField(ContactFormField $field): array
    {
        $phoneOptions = ContactFormFieldPhoneOptions::fromField($field);

        $options = array_merge($this->bundleDefaults, [
            'default_country' => $phoneOptions->defaultCountry,
            'value_format'    => $this->bundleDefaults['value_format'] ?? 'CONCATENATED',
        ]);

        if ($phoneOptions->allowedCountries !== null && $phoneOptions->allowedCountries !== []) {
            $options['allowed_countries'] = $phoneOptions->allowedCountries;
        }

        return $options;
    }
}
