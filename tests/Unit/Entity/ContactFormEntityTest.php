<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Entity;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactForm::class)]
#[CoversClass(ContactFormTranslation::class)]
#[CoversClass(ContactSubmission::class)]
final class ContactFormEntityTest extends TestCase
{
    public function testContactFormScalarsAndCollections(): void
    {
        $form = new ContactForm();
        $form->setName('Name')->setSlug('slug')->setEnabled(false)
            ->setPrivacyPolicyUrl('https://example.com/privacy')
            ->setRetentionDays(10)->setRequireConsent(true)->setNotificationEmail('a@b.c');

        self::assertSame('Name', $form->getName());
        self::assertFalse($form->isEnabled());
        self::assertSame(10, $form->getRetentionDays());

        $translation = (new ContactFormTranslation())->setLocale('en')->setTitle('Title');
        $form->addTranslation($translation);
        $form->removeTranslation($translation);

        $field = new ContactFormField();
        $form->addField($field);
        $form->removeField($field);
    }

    public function testTranslationLookupAndFallback(): void
    {
        $form = new ContactForm();
        $form->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('English'));
        $form->addTranslation((new ContactFormTranslation())->setLocale('es')->setTitle('Español'));

        self::assertSame('Español', $form->getTranslationForLocale('es')->getTitle());
        self::assertSame('English', $form->getTranslationForLocale('fr', 'en')->getTitle());
        self::assertSame('en', $form->findTranslation('en')?->getLocale());
    }

    public function testSubmissionAnonymousFlag(): void
    {
        $anonymous = new ContactSubmission();
        $linked    = (new ContactSubmission())->setClientId(5)->setClientLabel('Client');

        self::assertTrue($anonymous->isAnonymous());
        self::assertFalse($linked->isAnonymous());
        self::assertSame('Client', $linked->getClientLabel());
    }
}
