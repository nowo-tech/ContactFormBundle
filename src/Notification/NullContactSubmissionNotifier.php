<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

/**
 * No-op notifier used when notifications are disabled.
 */
final class NullContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function notify(ContactSubmissionNotification $notification): void
    {
    }
}
