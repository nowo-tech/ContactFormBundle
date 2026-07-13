<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Phone;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Enum\ContactPhoneWidget;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormFieldPhoneOptions::class)]
final class ContactFormFieldPhoneOptionsTest extends TestCase
{
    public function testParsesLegacyPrefixList(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions(['+34', '+1']);

        $options = ContactFormFieldPhoneOptions::fromField($field);

        self::assertSame(ContactPhoneWidget::Symfony, $options->widget);
        self::assertSame(['+34', '+1'], $options->prefixes);
    }

    public function testRoundTripsPhoneInputConfiguration(): void
    {
        $original = new ContactFormFieldPhoneOptions(
            widget: ContactPhoneWidget::PhoneInput,
            defaultCountry: 'ES',
            allowedCountries: ['ES', 'US'],
        );

        $field = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions($original->toStorage());

        $parsed = ContactFormFieldPhoneOptions::fromField($field);

        self::assertSame(ContactPhoneWidget::PhoneInput, $parsed->widget);
        self::assertSame('ES', $parsed->defaultCountry);
        self::assertSame(['ES', 'US'], $parsed->allowedCountries);
    }
}
