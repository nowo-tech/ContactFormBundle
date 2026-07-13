<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Demo alternative to ContactSubmissionNotifierInterface: react via Symfony events.
 */
final class ContactSubmissionDemoSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ContactSubmissionCreatedEvent::class => 'onSubmissionCreated',
        ];
    }

    public function onSubmissionCreated(ContactSubmissionCreatedEvent $event): void
    {
        $submission = $event->getSubmission();

        $this->logger->debug('ContactSubmissionCreatedEvent received (demo event subscriber).', [
            'submission_id' => $submission->getId(),
            'form_id'       => $submission->getForm()?->getId(),
            'anonymous'     => $submission->isAnonymous(),
        ]);
    }
}
