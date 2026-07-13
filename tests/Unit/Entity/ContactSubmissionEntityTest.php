<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactSubmission::class)]
final class ContactSubmissionEntityTest extends TestCase
{
    public function testScalarsAndMetadata(): void
    {
        $form       = (new ContactForm())->setName('Demo');
        $createdAt  = new DateTimeImmutable('2024-01-01');
        $consentAt  = new DateTimeImmutable('2024-01-02');
        $submission = (new ContactSubmission())
            ->setForm($form)
            ->setClientId(42)
            ->setClientLabel('Acme')
            ->setLocale('es')
            ->setIpHash('hash')
            ->setConsentGivenAt($consentAt)
            ->setCreatedAt($createdAt);

        self::assertSame($form, $submission->getForm());
        self::assertSame(42, $submission->getClientId());
        self::assertSame('Acme', $submission->getClientLabel());
        self::assertFalse($submission->isAnonymous());
        self::assertSame('es', $submission->getLocale());
        self::assertSame('hash', $submission->getIpHash());
        self::assertSame($consentAt, $submission->getConsentGivenAt());
        self::assertSame($createdAt, $submission->getCreatedAt());
    }

    public function testValueCollectionMutators(): void
    {
        $submission = new ContactSubmission();
        $value      = (new ContactSubmissionValue())->setFieldName('message')->setValue('Hello');

        $submission->addValue($value);
        self::assertCount(1, $submission->getValues());
        self::assertSame($submission, $value->getSubmission());

        $submission->addValue($value);
        self::assertCount(1, $submission->getValues());

        $submission->removeValue($value);
        self::assertCount(0, $submission->getValues());
        self::assertNull($value->getSubmission());
    }

    public function testConstructorDefaults(): void
    {
        $submission = new ContactSubmission();

        self::assertTrue($submission->isAnonymous());
        self::assertSame('en', $submission->getLocale());
        self::assertInstanceOf(DateTimeImmutable::class, $submission->getCreatedAt());
    }
}
