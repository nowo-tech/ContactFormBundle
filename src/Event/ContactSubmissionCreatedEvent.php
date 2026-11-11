<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Event;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a contact form submission is persisted (after the flush of the bundle's entity manager).
 *
 * 1.7 (architecture bar rule 4): the ids and values are captured when the event is created, so listeners that
 * reference the submission by id — and listeners that run after the entity was detached — read scalars only. The
 * entity ({@see self::getSubmission()}) stays for standard hosts.
 */
final class ContactSubmissionCreatedEvent extends Event
{
    private readonly ?int $submissionId;

    private readonly string $formSlug;

    private readonly string $locale;

    private readonly ?string $clientReference;

    /** @var array<string, string> */
    private readonly array $fieldValues;

    private readonly DateTimeImmutable $createdAt;

    public function __construct(
        private readonly ContactSubmission $submission,
    ) {
        $this->submissionId    = $submission->getId();
        $this->formSlug        = $submission->getForm()?->getSlug() ?? '';
        $this->locale          = $submission->getLocale();
        $this->clientReference = $submission->getClientReference();
        $this->createdAt       = $submission->getCreatedAt();
        $values                = [];
        foreach ($submission->getValues() as $value) {
            $values[$value->getFieldName()] = $value->getValue();
        }
        $this->fieldValues = $values;
    }

    public function getSubmission(): ContactSubmission
    {
        return $this->submission;
    }

    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Id of the host client as a string (any id type), null for an anonymous submission.
     */
    public function getClientReference(): ?string
    {
        return $this->clientReference;
    }

    /**
     * @return array<string, string> field name => stored (normalised) value
     */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
