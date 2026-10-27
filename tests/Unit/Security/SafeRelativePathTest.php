<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Security;

use Nowo\ContactFormBundle\Security\SafeRelativePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M1 (CWE-601): paths a browser would turn into a protocol-relative / absolute URL are never safe.
 */
#[CoversClass(SafeRelativePath::class)]
final class SafeRelativePathTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'protocol relative' => ['//evil.example/'];
        yield 'slash backslash (/%5C)' => ['/\\evil.example/'];
        yield 'slash tab (/%09/)' => ["/\t/evil.example/"];
        yield 'slash newline' => ["/\n/evil.example/"];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'decoded /%2F' => ['//evil.example'];
        yield 'absolute URL' => ['https://evil.example/'];
        yield 'space' => ['/ /evil.example'];
        yield 'DEL' => ["/\x7f/evil.example"];
        yield 'backslash later' => ['/admin/contact-forms\\..\\evil'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        self::assertFalse(SafeRelativePath::isSafe($path));
        self::assertFalse(SafeRelativePath::isSafeUnder($path, '/admin/contact-forms'));
    }

    public function testAcceptsSameOriginPathsInsideThePrefix(): void
    {
        self::assertTrue(SafeRelativePath::isSafe('/admin/contact-forms/3/fields?page=2'));
        self::assertTrue(SafeRelativePath::isSafeUnder('/admin/contact-forms', '/admin/contact-forms/'));
        self::assertTrue(SafeRelativePath::isSafeUnder('/admin/contact-forms/3/fields?page=2', '/admin/contact-forms'));
        self::assertTrue(SafeRelativePath::isSafeUnder('/anything', ''));
        self::assertFalse(SafeRelativePath::isSafeUnder('/admin/contact-forms-evil', '/admin/contact-forms'));
        self::assertFalse(SafeRelativePath::isSafeUnder('/account', '/admin/contact-forms'));
    }
}
