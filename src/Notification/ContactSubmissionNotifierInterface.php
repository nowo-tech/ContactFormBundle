<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

/**
 * Sends a notification when a contact form submission is created.
 *
 * Implement this interface in your application to use custom channels
 * (HTTP APIs, Slack, Symfony Notifier, etc.) without symfony/mailer.
 */
interface ContactSubmissionNotifierInterface
{
    public function notify(ContactSubmissionNotification $notification): void;
}
