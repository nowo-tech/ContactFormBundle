<?php

declare(strict_types=1);

namespace App\Notification;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function dirname;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Demo notifier: simulates an outbound webhook by writing the JSON payload to a log file.
 *
 * Replace this with a real HTTP client call in production (Slack, Zapier, custom API, etc.).
 */
final class WebhookContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function notify(ContactSubmissionNotification $notification): void
    {
        $path = $this->projectDir . '/var/log/contact-submissions-webhook-demo.log';
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'event'  => 'contact_form.submission.created',
            'form'   => $notification->getFormSlug(),
            'locale' => $notification->getSubmission()->getLocale(),
            'fields' => $notification->getFieldValues(),
            'client' => $notification->getSubmission()->isAnonymous()
                ? null
                : $notification->getSubmission()->getClientLabel(),
        ];

        $line = '[WEBHOOK] ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
