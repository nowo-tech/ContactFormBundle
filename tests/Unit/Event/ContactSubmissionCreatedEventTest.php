<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Event\ContactSubmissionsDeletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ContactSubmissionCreatedEvent::class)]
#[CoversClass(ContactSubmissionsDeletedEvent::class)]
final class ContactSubmissionCreatedEventTest extends TestCase
{
    public function testGetSubmission(): void
    {
        $submission = new ContactSubmission();
        $event      = new ContactSubmissionCreatedEvent($submission);

        self::assertSame($submission, $event->getSubmission());
        self::assertNull($event->getSubmissionId());
        self::assertSame('', $event->getFormSlug());
        self::assertNull($event->getClientReference());
        self::assertSame([], $event->getFieldValues());
    }

    public function testCarriesIdsAndValuesCapturedAtCreation(): void
    {
        $createdAt  = new DateTimeImmutable('2026-11-10 10:00:00');
        $submission = (new ContactSubmission())
            ->setForm((new ContactForm())->setName('Support')->setSlug('support'))
            ->setLocale('es')
            ->setClientReference('01890a5d-ac96-774b-bcce-b302099a8057')
            ->setCreatedAt($createdAt)
            ->addValue((new ContactSubmissionValue())->setFieldName('email')->setValue('visitor@example.com'));
        (new ReflectionProperty(ContactSubmission::class, 'id'))->setValue($submission, 12);

        $event = new ContactSubmissionCreatedEvent($submission);
        $submission->setLocale('en')->setClientReference(null);

        self::assertSame(12, $event->getSubmissionId());
        self::assertSame('support', $event->getFormSlug());
        self::assertSame('es', $event->getLocale());
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $event->getClientReference());
        self::assertSame(['email' => 'visitor@example.com'], $event->getFieldValues());
        self::assertSame($createdAt, $event->getCreatedAt());
    }

    public function testDeletedEvent(): void
    {
        $event = new ContactSubmissionsDeletedEvent([3, 4], ContactSubmissionsDeletedEvent::REASON_ADMIN);

        self::assertSame([3, 4], $event->getSubmissionIds());
        self::assertSame('admin', $event->getReason());
    }
}
