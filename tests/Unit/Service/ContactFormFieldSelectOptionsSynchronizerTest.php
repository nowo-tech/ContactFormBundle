<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormFieldSelectOptionsSynchronizer::class)]
final class ContactFormFieldSelectOptionsSynchronizerTest extends TestCase
{
    private ContactFormFieldSelectOptionsSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->synchronizer = new ContactFormFieldSelectOptionsSynchronizer();
    }

    public function testSynchronizeGeneratesStableValuesForNewSelectField(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Topic')
                    ->setSelectOptions(['General inquiry', 'Technical support']),
            );

        $this->synchronizer->synchronize($field);

        self::assertSame(['general_inquiry', 'technical_support'], $field->getOptions());
    }

    public function testSynchronizeKeepsExistingValuesWhenCountMatches(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->setOptions(['general_inquiry', 'technical_support'])
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setSelectOptions(['General inquiry', 'Technical support']),
            )
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('es')
                    ->setSelectOptions(['Consulta general', 'Soporte técnico']),
            );

        $this->synchronizer->synchronize($field);

        self::assertSame(['general_inquiry', 'technical_support'], $field->getOptions());
    }

    public function testSynchronizeClearsOptionsForNonSelectField(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Text)
            ->setOptions(['legacy'])
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setSelectOptions(['Legacy']),
            );

        $this->synchronizer->synchronize($field);

        self::assertNull($field->getOptions());
        self::assertNull($field->findTranslation('en')?->getSelectOptions());
    }

    public function testSynchronizeGeneratesFallbackSlugForEmptyLabel(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setSelectOptions(['', 'Valid']),
            );

        $this->synchronizer->synchronize($field);

        self::assertSame(['option_1', 'valid'], $field->getOptions());
    }

    public function testSynchronizeClearsOptionsWhenNoLabels(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::Select);

        $this->synchronizer->synchronize($field);

        self::assertNull($field->getOptions());
    }
}
