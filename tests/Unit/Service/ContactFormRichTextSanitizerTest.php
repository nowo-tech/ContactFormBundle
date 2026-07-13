<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormRichTextSanitizer::class)]
final class ContactFormRichTextSanitizerTest extends TestCase
{
    private ContactFormRichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ContactFormRichTextSanitizer();
    }

    public function testSanitizeReturnsEmptyForNullOrBlank(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(null));
        self::assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function testSanitizeKeepsAllowedTagsAndStripsScripts(): void
    {
        $result = $this->sanitizer->sanitize('<p>Hello</p><strong>World</strong><script>alert(1)</script>');

        self::assertStringContainsString('<strong>World</strong>', $result);
        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('Hello', $result);
    }

    public function testSanitizeHardensExternalLinks(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://example.com">Privacy</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $result);
        self::assertStringContainsString('target="_blank"', $result);
    }

    public function testSanitizeRemovesDisallowedHref(): void
    {
        $result = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Bad</a>');

        self::assertStringNotContainsString('javascript:', $result);
    }
}
