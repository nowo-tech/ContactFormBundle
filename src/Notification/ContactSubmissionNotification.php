<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactSubmission;

/**
 * Immutable snapshot passed to submission notifiers.
 *
 * 1.8 (REQ-ARCH-001 rule 1): the submission id, locale, client reference / label and creation time are captured as
 * scalars when the snapshot is built ({@see self::fromSubmission()}), so a notifier needs no entity. The entity
 * ({@see self::getSubmission()}) stays for standard hosts until 2.0.
 */
final readonly class ContactSubmissionNotification
{
    private ?int $submissionId;

    private string $locale;

    private ?string $clientReference;

    private ?string $clientLabel;

    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, string> $fieldValues
     */
    public function __construct(
        private ContactSubmission $submission,
        private string $formName,
        private string $formSlug,
        private array $fieldValues,
        private ?string $notificationRecipient,
    ) {
        $this->submissionId    = $submission->getId();
        $this->locale          = $submission->getLocale();
        $this->clientReference = $submission->getClientReference();
        $this->clientLabel     = $submission->getClientLabel();
        $this->createdAt       = $submission->getCreatedAt();
    }

    public static function fromSubmission(ContactSubmission $submission, ?string $defaultRecipient = null): self
    {
        $form   = $submission->getForm();
        $values = [];

        foreach ($submission->getValues() as $value) {
            $values[$value->getFieldName()] = $value->getValue();
        }

        $recipient = $form?->getNotificationEmail() ?? $defaultRecipient;

        return new self(
            $submission,
            $form?->getName() ?? '',
            $form?->getSlug() ?? '',
            $values,
            $recipient,
        );
    }

    /**
     * @deprecated since 1.8, use the id and scalar accessors ({@see self::getSubmissionId()}, {@see self::getLocale()},
     *             {@see self::getClientReference()}, {@see self::getClientLabel()}, {@see self::getCreatedAt()},
     *             {@see self::getFieldValues()}); removed in 2.0
     */
    public function getSubmission(): ContactSubmission
    {
        return $this->submission;
    }

    /** Id of the stored submission (1.8). */
    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
    }

    /** Locale of the submission (1.8). */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /** Id of the host client as a string (any id type), null for an anonymous submission (1.8). */
    public function getClientReference(): ?string
    {
        return $this->clientReference;
    }

    /** Display label of the host client, null when there is none (1.8). Host data: escape it in your output. */
    public function getClientLabel(): ?string
    {
        return $this->clientLabel;
    }

    /** True when the submission has no client reference and no client label (1.8). */
    public function isAnonymous(): bool
    {
        return $this->clientReference === null && $this->clientLabel === null;
    }

    /** When the submission was created (1.8). */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFormName(): string
    {
        return $this->formName;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    /**
     * @return array<string, string>
     */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    public function getNotificationRecipient(): ?string
    {
        return $this->notificationRecipient;
    }
}
