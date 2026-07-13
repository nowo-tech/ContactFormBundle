<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Event;

use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactSubmissionCreatedEvent::class)]
final class ContactSubmissionCreatedEventTest extends TestCase
{
    public function testGetSubmission(): void
    {
        $submission = new ContactSubmission();
        $event      = new ContactSubmissionCreatedEvent($submission);

        self::assertSame($submission, $event->getSubmission());
    }
}
