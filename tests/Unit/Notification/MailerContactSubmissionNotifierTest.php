<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Notification;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[CoversClass(MailerContactSubmissionNotifier::class)]
final class MailerContactSubmissionNotifierTest extends TestCase
{
    public function testNotifySendsEmailWhenRecipientIsSet(): void
    {
        $form = (new ContactForm())
            ->setName('Contact')
            ->setSlug('contact')
            ->setNotificationEmail('admin@example.com');

        $submission = (new ContactSubmission())
            ->setForm($form)
            ->setLocale('en')
            ->addValue(
                (new ContactSubmissionValue())
                    ->setFieldName('message')
                    ->setValue('Hello'),
            );

        $notification = ContactSubmissionNotification::fromSubmission($submission);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                return $email->getTo()[0]->getAddress() === 'admin@example.com'
                    && str_contains($email->getSubject(), 'Contact')
                    && str_contains($email->getTextBody() ?? '', 'message: Hello');
            }));

        $notifier = new MailerContactSubmissionNotifier(
            $mailer,
            'noreply@example.com',
            'New contact submission: {form}',
        );

        $notifier->notify($notification);
    }

    public function testNotifySkipsWhenRecipientIsEmpty(): void
    {
        $submission   = new ContactSubmission();
        $notification = new ContactSubmissionNotification($submission, '', '', [], null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $notifier = new MailerContactSubmissionNotifier(
            $mailer,
            'noreply@example.com',
            'Subject',
        );

        $notifier->notify($notification);
    }
}
