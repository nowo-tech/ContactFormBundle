<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
            ->setClientReference('42')
            ->setClientLabel('Acme')
            ->setLocale('es')
            ->setIpHash('hash')
            ->setConsentGivenAt($consentAt)
            ->setCreatedAt($createdAt);

        self::assertSame($form, $submission->getForm());
        self::assertSame('42', $submission->getClientReference());
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
        $createdAt  = $submission->getCreatedAt();

        self::assertTrue($submission->isAnonymous());
        self::assertSame('en', $submission->getLocale());
        self::assertSame(DateTimeImmutable::class, $createdAt::class);
    }

    public function testSubmissionWithOnlyAClientLabelIsNotAnonymous(): void
    {
        $submission = (new ContactSubmission())->setClientLabel('client@example.com');

        self::assertNull($submission->getClientReference());
        self::assertFalse($submission->isAnonymous());
    }

    public function testRemovedClientLinkMakesTheSubmissionAnonymousAgain(): void
    {
        $submission = (new ContactSubmission())->setClientReference('7')->setClientLabel('x');
        $submission->setClientReference(null)->setClientLabel(null);

        self::assertTrue($submission->isAnonymous());
    }

    public function testClientReferenceHoldsAnyIdTypeWithoutTheRemovedIntegerColumn(): void
    {
        $submission = (new ContactSubmission())->setClientReference('01890a5d-ac96-774b-bcce-b302099a8057');
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $submission->getClientReference());
        self::assertFalse($submission->isAnonymous());
        self::assertNull((new ContactSubmission())->getClientReference());

        // 2.0 removed the INTEGER client_id column and its accessors (deprecated since 1.7).
        $class = new ReflectionClass(ContactSubmission::class);
        self::assertFalse($class->hasProperty('clientId'));
        self::assertFalse($class->hasMethod('getClientId'));
        self::assertFalse($class->hasMethod('setClientId'));
    }
}
