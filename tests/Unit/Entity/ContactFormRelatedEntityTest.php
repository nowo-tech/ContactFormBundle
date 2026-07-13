<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Entity;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormField::class)]
#[CoversClass(ContactFormFieldTranslation::class)]
#[CoversClass(ContactSubmissionValue::class)]
#[CoversClass(ContactFormTranslation::class)]
final class ContactFormRelatedEntityTest extends TestCase
{
    public function testContactFormFieldRelationsAndTranslationFallback(): void
    {
        $form  = new ContactForm();
        $field = (new ContactFormField())
            ->setName('phone')
            ->setType(ContactFieldType::Phone)
            ->setRequired(true)
            ->setSortOrder(5)
            ->setOptions(['a'])
            ->setFlowStep('definition');

        $form->addField($field);
        $field->addTranslation(
            (new ContactFormFieldTranslation())
                ->setLocale('es')
                ->setLabel('Teléfono')
                ->setPlaceholder('600')
                ->setHelp('Help')
                ->setSelectOptions(['Uno']),
        );

        self::assertSame($form, $field->getForm());
        self::assertTrue($form->getFields()->contains($field));
        self::assertSame('Teléfono', $field->getTranslationForLocale('es')->getLabel());
        self::assertSame('Teléfono', $field->getTranslationForLocale('fr', 'es')->getLabel());
        self::assertSame('es', $field->getTranslationForLocale('fr')->getLocale());
        self::assertSame('definition', $field->getFlowStep());

        $field->setName(null)->setType(null)->setSortOrder(null);
        self::assertSame('phone', $field->getName());

        $field->removeTranslation($field->findTranslation('es') ?? new ContactFormFieldTranslation());
        self::assertCount(0, $field->getTranslations());
    }

    public function testContactFormTranslationAndFieldCollectionMutators(): void
    {
        $form = new ContactForm();
        $form->setName('Name')->setSlug('slug')->setEnabled(false)
            ->setPrivacyPolicyUrl('https://example.com/privacy')
            ->setRetentionDays(10)->setRequireConsent(true)->setNotificationEmail('a@b.c');

        $translation = (new ContactFormTranslation())
            ->setLocale('en')
            ->setTitle('Title')
            ->setDescription('Desc')
            ->setSuccessMessage('OK')
            ->setConsentLabel('Consent')
            ->setPrivacyPolicyText('Policy');

        $form->addTranslation($translation);
        self::assertSame($form, $translation->getForm());
        $form->removeTranslation($translation);
        self::assertNull($translation->getForm());

        $field = new ContactFormField();
        $form->addField($field);
        $form->removeField($field);
        self::assertNull($field->getForm());
    }

    public function testSubmissionValueCollection(): void
    {
        $submission = new ContactSubmission();
        $value      = (new ContactSubmissionValue())->setFieldName('msg')->setValue('Hi');

        $submission->addValue($value);
        self::assertSame($submission, $value->getSubmission());
        $submission->removeValue($value);
        self::assertNull($value->getSubmission());
    }

    public function testContactFormTranslationForLocaleCreatesPlaceholder(): void
    {
        $form = new ContactForm();

        $translation = $form->getTranslationForLocale('fr');

        self::assertSame('fr', $translation->getLocale());
    }
}
