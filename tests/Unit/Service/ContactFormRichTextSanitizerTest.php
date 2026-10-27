<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function nestedPayloadProvider(): iterable
    {
        yield 'img in p' => ['<p><img src=x onerror=alert(1)></p>'];
        yield 'svg onload in div' => ['<div><svg onload=alert(1)></svg></div>'];
        yield 'deeply nested' => ['<div><p><section><img src=x onerror=alert(1)></section></p></div>'];
        yield 'allowed inside unwrap' => ['<p><a href="javascript:alert(1)" onclick="alert(1)">x</a></p>'];
        yield 'span attrs in p' => ['<p><span onmouseover="alert(1)" style="x">t</span></p>'];
        yield 'iframe in strong in p' => ['<p><strong><iframe src="https://evil"></iframe></strong></p>'];
    }

    #[DataProvider('nestedPayloadProvider')]
    public function testSanitizeStripsDisallowedMarkupNestedInUnwrappedElements(string $payload): void
    {
        $result = $this->sanitizer->sanitize($payload);

        self::assertDoesNotMatchRegularExpression('/<(img|svg|iframe|p|div|section)\b/i', $result);
        self::assertStringNotContainsStringIgnoringCase('onerror', $result);
        self::assertStringNotContainsStringIgnoringCase('onload', $result);
        self::assertStringNotContainsStringIgnoringCase('onclick', $result);
        self::assertStringNotContainsStringIgnoringCase('onmouseover', $result);
        self::assertStringNotContainsStringIgnoringCase('style=', $result);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $result);
    }

    public function testSanitizeKeepsTextAndAllowedTagsFromUnwrappedElements(): void
    {
        $result = $this->sanitizer->sanitize('<p>I accept the <a href="/privacy" onclick="x()">policy</a> <b>now</b></p>');

        self::assertSame(
            'I accept the <a href="/privacy" rel="noopener noreferrer" target="_blank">policy</a> <b>now</b>',
            $result,
        );
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

    /**
     * I1: comments, processing instructions and CDATA never survive; links to "/\\host" are dropped (shared M1 rule).
     */
    public function testDropsCommentsProcessingInstructionsAndBackslashLinks(): void
    {
        $sanitizer = new ContactFormRichTextSanitizer();

        self::assertSame('I agree', $sanitizer->sanitize('I agree<!-- <script>x</script> --><?php echo 1 ?>'));
        self::assertStringNotContainsString('CDATA', $sanitizer->sanitize('<b><![CDATA[x]]></b>'));
        self::assertStringNotContainsString('href', $sanitizer->sanitize('<a href="/\\evil.example">x</a>'));
        self::assertStringNotContainsString('href', $sanitizer->sanitize('<a href="\\\\evil.example">x</a>'));
        self::assertStringContainsString('href="/privacy"', $sanitizer->sanitize('<a href="/privacy">x</a>'));
    }
}
