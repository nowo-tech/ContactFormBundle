<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(ContactFormFieldDefinitionStepType::class)]
final class ContactFormFieldDefinitionStepTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new ContactFormFieldDefinitionStepType()),
        ];
    }

    public function testBuildsDefinitionFields(): void
    {
        $form = $this->factory->create(ContactFormFieldDefinitionStepType::class);

        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('type'));
        self::assertTrue($form->has('required'));
        self::assertTrue($form->has('sortOrder'));
    }
}
