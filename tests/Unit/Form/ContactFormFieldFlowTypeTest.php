<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Form\ContactFormFieldFlowType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormFieldFlowType::class)]
final class ContactFormFieldFlowTypeTest extends TestCase
{
    public function testIsValidStep(): void
    {
        self::assertTrue(ContactFormFieldFlowType::isValidStep(ContactFormFieldFlowType::STEP_DEFINITION));
        self::assertTrue(ContactFormFieldFlowType::isValidStep(ContactFormFieldFlowType::STEP_PHONE_PREFIXES));
        self::assertTrue(ContactFormFieldFlowType::isValidStep(ContactFormFieldFlowType::STEP_CONTENT));
        self::assertFalse(ContactFormFieldFlowType::isValidStep('invalid'));
    }
}
