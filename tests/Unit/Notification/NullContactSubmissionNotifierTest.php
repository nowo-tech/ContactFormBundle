<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Notification;

use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullContactSubmissionNotifier::class)]
final class NullContactSubmissionNotifierTest extends TestCase
{
    public function testNotifyDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $notifier = new NullContactSubmissionNotifier();
        $notifier->notify(new ContactSubmissionNotification(new ContactSubmission(), '', '', [], null));
    }
}
