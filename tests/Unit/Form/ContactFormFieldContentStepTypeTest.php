<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactFormFieldContentStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ContactFormFieldContentStepType::class)]
final class ContactFormFieldContentStepTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);

        return [
            new ContactFormFieldContentStepType(new ContactFormFieldSelectOptionsSynchronizer(), $translator),
            new ContactFormFieldTranslationType(),
        ];
    }

    public function testBuildsContentFields(): void
    {
        $form = $this->factory->create(ContactFormFieldContentStepType::class);

        self::assertTrue($form->has('translations'));
        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('type'));
    }
}
