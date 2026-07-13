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
 * Demo notifier: appends each submission as JSON lines to a local file (no mailer required).
 */
final class FileContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function notify(ContactSubmissionNotification $notification): void
    {
        $path = $this->projectDir . '/var/log/contact-submissions-demo.log';
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'form'   => $notification->getFormSlug(),
            'locale' => $notification->getSubmission()->getLocale(),
            'fields' => $notification->getFieldValues(),
            'at'     => $notification->getSubmission()->getCreatedAt()->format('c'),
        ], JSON_THROW_ON_ERROR) . "\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
