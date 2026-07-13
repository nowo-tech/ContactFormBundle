<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ContactPhoneValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactPhoneValue::class)]
final class ContactPhoneValueTest extends TestCase
{
    public function testCombineStripsNonDigitsFromNumber(): void
    {
        self::assertSame('+34600111222', ContactPhoneValue::combine('+34', '600 111 222'));
    }

    public function testSplitUsesLongestMatchingPrefix(): void
    {
        $parts = ContactPhoneValue::split('+34600111222', ['+34', '+3']);

        self::assertSame('+34', $parts['prefix']);
        self::assertSame('600111222', $parts['number']);
    }

    public function testSplitReturnsDefaultsForEmptyValue(): void
    {
        $parts = ContactPhoneValue::split(null, ['+34', '+1']);

        self::assertSame('+34', $parts['prefix']);
        self::assertSame('', $parts['number']);
    }
}
