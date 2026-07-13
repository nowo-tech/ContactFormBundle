<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Enum;

use Nowo\ContactFormBundle\Enum\ContactFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFieldType::class)]
final class ContactFieldTypeTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame(
            ['text', 'email', 'phone', 'textarea', 'select', 'checkbox', 'number', 'date', 'url', 'file'],
            ContactFieldType::values(),
        );
    }
}
