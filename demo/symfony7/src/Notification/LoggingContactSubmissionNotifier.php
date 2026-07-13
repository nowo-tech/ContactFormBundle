<?php

declare(strict_types=1);

namespace App\Notification;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Demo notifier: writes submission summaries to the application log (PSR-3).
 */
final class LoggingContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(ContactSubmissionNotification $notification): void
    {
        $this->logger->info('Contact form submission received (demo logging notifier).', [
            'form'   => $notification->getFormSlug(),
            'locale' => $notification->getSubmission()->getLocale(),
            'fields' => $notification->getFieldValues(),
            'client' => $notification->getSubmission()->getClientLabel(),
        ]);
    }
}
