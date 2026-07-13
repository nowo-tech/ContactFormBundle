<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Notification;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactSubmissionNotification::class)]
final class ContactSubmissionNotificationTest extends TestCase
{
    public function testFromSubmissionBuildsSnapshot(): void
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setNotificationEmail('admin@example.com');

        $submission = (new ContactSubmission())->setForm($form);
        $submission->addValue(
            (new ContactSubmissionValue())->setFieldName('email')->setValue('user@example.com'),
        );

        $notification = ContactSubmissionNotification::fromSubmission($submission);

        self::assertSame($submission, $notification->getSubmission());
        self::assertSame('Support', $notification->getFormName());
        self::assertSame('support', $notification->getFormSlug());
        self::assertSame(['email' => 'user@example.com'], $notification->getFieldValues());
        self::assertSame('admin@example.com', $notification->getNotificationRecipient());
    }

    public function testFromSubmissionUsesDefaultRecipient(): void
    {
        $submission = new ContactSubmission();

        $notification = ContactSubmissionNotification::fromSubmission($submission, 'fallback@example.com');

        self::assertSame('', $notification->getFormName());
        self::assertSame('fallback@example.com', $notification->getNotificationRecipient());
    }
}
