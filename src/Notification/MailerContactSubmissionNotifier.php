<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends submission notifications via symfony/mailer when installed.
 */
final readonly class MailerContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private string $from,
        private string $subjectTemplate,
    ) {
    }

    public function notify(ContactSubmissionNotification $notification): void
    {
        $recipient = $notification->getNotificationRecipient();

        if ($recipient === null || $recipient === '') {
            return;
        }

        $submission = $notification->getSubmission();
        $bodyLines  = [
            'Form: ' . $notification->getFormName(),
            'Slug: ' . $notification->getFormSlug(),
            'Locale: ' . $submission->getLocale(),
            'Submitted at: ' . $submission->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        if (!$submission->isAnonymous()) {
            $bodyLines[] = 'Client: ' . ($submission->getClientLabel() ?? (string) $submission->getClientId());
        }

        $bodyLines[] = '';
        $bodyLines[] = 'Fields:';

        foreach ($notification->getFieldValues() as $name => $value) {
            $bodyLines[] = $name . ': ' . $value;
        }

        $email = (new Email())
            ->from($this->from)
            ->to($recipient)
            ->subject(str_replace('{form}', $notification->getFormName(), $this->subjectTemplate))
            ->text(implode("\n", $bodyLines));

        $this->mailer->send($email);
    }
}
