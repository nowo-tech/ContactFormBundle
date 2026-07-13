<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Event;

use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a contact form submission is persisted.
 */
final class ContactSubmissionCreatedEvent extends Event
{
    public function __construct(
        private readonly ContactSubmission $submission,
    ) {
    }

    public function getSubmission(): ContactSubmission
    {
        return $this->submission;
    }
}
