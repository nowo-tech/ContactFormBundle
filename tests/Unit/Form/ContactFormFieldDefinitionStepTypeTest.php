<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(ContactFormFieldDefinitionStepType::class)]
final class ContactFormFieldDefinitionStepTypeTest extends TypeTestCase
{
    public function testBuildsDefinitionFields(): void
    {
        $form = $this->factory->create(ContactFormFieldDefinitionStepType::class);

        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('type'));
        self::assertTrue($form->has('required'));
        self::assertTrue($form->has('sortOrder'));
    }
}
