<?php

declare(strict_types=1);

namespace App\Notification;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;

/**
 * Demo composite notifier: chains logging + file + webhook-style output (no symfony/mailer required).
 */
final class CompositeContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function __construct(
        private readonly LoggingContactSubmissionNotifier $loggingNotifier,
        private readonly FileContactSubmissionNotifier $fileNotifier,
        private readonly WebhookContactSubmissionNotifier $webhookNotifier,
    ) {
    }

    public function notify(ContactSubmissionNotification $notification): void
    {
        $this->loggingNotifier->notify($notification);
        $this->fileNotifier->notify($notification);
        $this->webhookNotifier->notify($notification);
    }
}
