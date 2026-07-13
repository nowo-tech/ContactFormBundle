<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Notification;

use Nowo\ContactFormBundle\Entity\ContactSubmission;

/**
 * Immutable snapshot passed to submission notifiers.
 */
final class ContactSubmissionNotification
{
    /**
     * @param array<string, string> $fieldValues
     */
    public function __construct(
        private readonly ContactSubmission $submission,
        private readonly string $formName,
        private readonly string $formSlug,
        private readonly array $fieldValues,
        private readonly ?string $notificationRecipient,
    ) {
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

    public function getSubmission(): ContactSubmission
    {
        return $this->submission;
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
