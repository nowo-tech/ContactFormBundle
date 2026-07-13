<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Phone;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Enum\ContactPhoneWidget;

use function is_array;
use function is_string;

/**
 * Parsed phone-field configuration stored in ContactFormField::options.
 */
final readonly class ContactFormFieldPhoneOptions
{
    /**
     * @param list<string> $prefixes
     * @param list<string>|null $allowedCountries
     */
    public function __construct(
        public ContactPhoneWidget $widget = ContactPhoneWidget::Symfony,
        public array $prefixes = [],
        public string $defaultCountry = 'ES',
        public ?array $allowedCountries = null,
    ) {
    }

    public static function fromField(ContactFormField $field): self
    {
        if ($field->getType() !== ContactFieldType::Phone) {
            return new self();
        }

        $raw = $field->getOptions();

        if ($raw === null || $raw === []) {
            return new self();
        }

        if (array_is_list($raw)) {
            /* @var list<string> $raw */
            return new self(ContactPhoneWidget::Symfony, $raw);
        }

        $widget = ContactPhoneWidget::tryFrom((string) ($raw['widget'] ?? '')) ?? ContactPhoneWidget::Symfony;

        $prefixes = [];
        if (is_array($raw['prefixes'] ?? null)) {
            foreach ($raw['prefixes'] as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $prefixes[] = $prefix;
                }
            }
        }

        $allowedCountries = null;
        if (is_array($raw['allowed_countries'] ?? null)) {
            $allowedCountries = [];
            foreach ($raw['allowed_countries'] as $iso) {
                if (is_string($iso) && $iso !== '') {
                    $allowedCountries[] = strtoupper($iso);
                }
            }
        }

        $defaultCountry = is_string($raw['default_country'] ?? null) && $raw['default_country'] !== ''
            ? strtoupper($raw['default_country'])
            : 'ES';

        return new self($widget, $prefixes, $defaultCountry, $allowedCountries);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toStorage(): ?array
    {
        if ($this->widget === ContactPhoneWidget::Symfony) {
            if ($this->prefixes === []) {
                return null;
            }

            return [
                'widget'   => $this->widget->value,
                'prefixes' => $this->prefixes,
            ];
        }

        $data = [
            'widget'          => $this->widget->value,
            'default_country' => $this->defaultCountry,
        ];

        if ($this->allowedCountries !== null && $this->allowedCountries !== []) {
            $data['allowed_countries'] = $this->allowedCountries;
        }

        return $data;
    }
}
