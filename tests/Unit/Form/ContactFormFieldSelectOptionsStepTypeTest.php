<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactFormFieldSelectOptionsStepType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ContactFormFieldSelectOptionsStepType::class)]
final class ContactFormFieldSelectOptionsStepTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return [
            FormKitTestSupport::withMerger(new ContactFormFieldSelectOptionsStepType($translator)),
        ];
    }

    public function testSubmitStoresMachineNames(): void
    {
        $field = (new ContactFormField())
            ->setName('topic')
            ->setType(ContactFieldType::Select);

        $form = $this->factory->createBuilder(FormType::class, $field)
            ->add('step', ContactFormFieldSelectOptionsStepType::class)
            ->getForm();

        $form->submit([
            'step' => [
                'name'                     => 'topic',
                'type'                     => ContactFieldType::Select->value,
                'required'                 => '0',
                'sortOrder'                => '0',
                'optionsMachineNamesLines' => "general_inquiry\ntechnical_support",
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['general_inquiry', 'technical_support'], $field->getOptions());
    }

    public function testSubmitRejectsInvalidMachineName(): void
    {
        $field = (new ContactFormField())
            ->setName('topic')
            ->setType(ContactFieldType::Select);

        $form = $this->factory->createBuilder(FormType::class, $field)
            ->add('step', ContactFormFieldSelectOptionsStepType::class)
            ->getForm();

        $form->submit([
            'step' => [
                'name'                     => 'topic',
                'type'                     => ContactFieldType::Select->value,
                'required'                 => '0',
                'sortOrder'                => '0',
                'optionsMachineNamesLines' => 'Invalid-Name',
            ],
        ]);

        self::assertFalse($form->get('step')->isValid());
    }
}
