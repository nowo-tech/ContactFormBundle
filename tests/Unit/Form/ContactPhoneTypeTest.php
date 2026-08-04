<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Validation;

#[CoversClass(ContactPhoneType::class)]
final class ContactPhoneTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new ContactPhoneType()),
        ];
    }

    public function testSubmitCombinesPrefixAndNumber(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)', '+1' => 'US (+1)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => '600 111 222',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame('+34600111222', $form->getData());
        self::assertSame('contact_phone', $form->getConfig()->getType()->getBlockPrefix());
    }

    public function testSubmitEmptyReturnsNull(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => '',
        ]);

        self::assertNull($form->getData());
    }

    public function testViewDataSplitsCombinedNumber(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, '+34600111222', [
            'prefixes' => ['+34' => 'ES (+34)', '+1' => 'US (+1)'],
        ]);

        self::assertSame('+34', $form->get('prefix')->getData());
        self::assertSame('600111222', $form->get('number')->getData());
    }

    public function testViewDataUsesDefaultPrefixForEmptyValue(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        self::assertSame('+34', $form->get('prefix')->getData());
        self::assertSame('', $form->get('number')->getData());
    }

    public function testSubmitNumberWithoutPrefixUsesCombine(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '',
            'number' => '600111222',
        ]);

        self::assertSame('600111222', $form->getData());
    }

    public function testSubmitInvalidNumberFailsValidation(): void
    {
        $form = $this->factory->create(ContactPhoneType::class, null, [
            'prefixes' => ['+34' => 'ES (+34)'],
        ]);

        $form->submit([
            'prefix' => '+34',
            'number' => 'abc',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testConfigureOptionsRequiresPrefixes(): void
    {
        $resolver = new OptionsResolver();
        (new ContactPhoneType())->configureOptions($resolver);

        self::assertSame([], $resolver->resolve(['prefixes' => []])['prefixes']);
    }
}
