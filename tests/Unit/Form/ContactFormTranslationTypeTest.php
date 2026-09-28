<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormTranslationType;
use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(ContactFormTranslationType::class)]
final class ContactFormTranslationTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new ContactFormTranslationType(new ContactFormRichTextSanitizer())),
        ];
    }

    public function testSubmitWithHiddenLocale(): void
    {
        $translation = (new ContactFormTranslation())->setLocale('en');

        $form = $this->factory->create(ContactFormTranslationType::class, $translation, [
            'hide_locale' => true,
        ]);

        $form->submit([
            'locale'            => 'en',
            'title'             => 'Title',
            'description'       => 'Description',
            'successMessage'    => 'Thanks',
            'consentLabel'      => 'I agree',
            'privacyPolicyText' => 'Policy',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Title', $translation->getTitle());
        self::assertSame('Thanks', $translation->getSuccessMessage());
    }

    public function testSubmitSanitizesConsentLabelHtml(): void
    {
        $translation = (new ContactFormTranslation())->setLocale('en');

        $form = $this->factory->create(ContactFormTranslationType::class, $translation, [
            'hide_locale' => true,
        ]);

        $form->submit([
            'locale'       => 'en',
            'title'        => 'Title',
            'consentLabel' => 'I agree <script>alert(1)</script><a href="https://example.com/privacy">privacy</a>',
        ]);

        self::assertTrue($form->isSynchronized());
        $label = (string) $translation->getConsentLabel();
        self::assertStringNotContainsString('<script>', $label);
        self::assertStringContainsString('https://example.com/privacy', $label);
    }

    public function testSubmitWithVisibleLocaleField(): void
    {
        $translation = new ContactFormTranslation();

        $form = $this->factory->create(ContactFormTranslationType::class, $translation, [
            'hide_locale' => false,
        ]);

        $form->submit([
            'locale' => 'fr',
            'title'  => 'Bonjour',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('fr', $translation->getLocale());
    }
}
