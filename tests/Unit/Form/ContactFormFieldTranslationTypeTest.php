<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(ContactFormFieldTranslationType::class)]
final class ContactFormFieldTranslationTypeTest extends TypeTestCase
{
    public function testSubmitWithSelectOptionsField(): void
    {
        $translation = (new ContactFormFieldTranslation())->setLocale('en');

        $form = $this->factory->create(ContactFormFieldTranslationType::class, $translation, [
            'hide_locale'         => true,
            'show_select_options' => true,
        ]);

        $form->submit([
            'locale'             => 'en',
            'label'              => 'Topic',
            'placeholder'        => 'Choose',
            'help'               => 'Help text',
            'selectOptionsLines' => "General\nSupport",
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Topic', $translation->getLabel());
        self::assertSame('Choose', $translation->getPlaceholder());
    }

    public function testSubmitWithoutSelectOptionsField(): void
    {
        $translation = (new ContactFormFieldTranslation())->setLocale('es');

        $form = $this->factory->create(ContactFormFieldTranslationType::class, $translation);

        $form->submit([
            'locale' => 'es',
            'label'  => 'Correo',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->has('selectOptionsLines'));
    }
}
