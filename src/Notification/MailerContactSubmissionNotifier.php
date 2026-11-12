<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

use function str_replace;

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

        // 1.8: scalars of the snapshot only, no entity.
        $bodyLines = [
            'Form: ' . $notification->getFormName(),
            'Slug: ' . $notification->getFormSlug(),
            'Locale: ' . $notification->getLocale(),
            'Submitted at: ' . $notification->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        if (!$notification->isAnonymous()) {
            // The label comes from host data: no line breaks, so it cannot fake other lines of the body.
            $bodyLines[] = 'Client: ' . str_replace(["\r\n", "\r", "\n"], ' ', $notification->getClientLabel() ?? (string) $notification->getClientReference());
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
