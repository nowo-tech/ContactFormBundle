<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(ContactFormSubmissionValueNormalizer::class)]
final class ContactFormSubmissionValueNormalizerTest extends TestCase
{
    private ContactFormSubmissionValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $handler = $this->createMock(ContactFormFileUploadHandlerInterface::class);
        $handler->method('upload')->willReturn('uploads/file.pdf');

        $this->normalizer = new ContactFormSubmissionValueNormalizer($handler);
    }

    public function testNormalizeCheckboxValues(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::Checkbox);
        $form  = new ContactForm();

        self::assertSame('1', $this->normalizer->normalize($field, true, $form));
        self::assertSame('1', $this->normalizer->normalize($field, '1', $form));
        self::assertSame('0', $this->normalizer->normalize($field, false, $form));
    }

    public function testNormalizeDateValue(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::Date);
        $form  = new ContactForm();
        $date  = new DateTimeImmutable('2024-06-01');

        self::assertSame('2024-06-01', $this->normalizer->normalize($field, $date, $form));
        self::assertSame('2024-06-01', $this->normalizer->normalize($field, '2024-06-01', $form));
    }

    public function testNormalizeFileUpload(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::File);
        $form  = new ContactForm();
        $file  = new UploadedFile(__FILE__, 'test.php', null, null, true);

        self::assertSame('uploads/file.pdf', $this->normalizer->normalize($field, $file, $form));
        self::assertSame('', $this->normalizer->normalize($field, null, $form));
    }

    public function testNormalizeScalarText(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::Text);
        $form  = new ContactForm();

        self::assertSame('hello', $this->normalizer->normalize($field, 'hello', $form));
        self::assertSame('', $this->normalizer->normalize($field, ['array'], $form));
    }
}
