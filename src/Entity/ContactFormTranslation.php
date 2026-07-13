<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Repository\ContactFormTranslationRepository;

#[ORM\Entity(repositoryClass: ContactFormTranslationRepository::class)]
#[ORM\Table(name: 'nowo_contact_form_translation')]
#[ORM\UniqueConstraint(name: 'uniq_contact_form_translation_locale', columns: ['form_id', 'locale'])]
/**
 * Locale-specific copy for a contact form (title, GDPR text, success message).
 */
class ContactFormTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $locale = 'en';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'success_message', type: Types::TEXT, nullable: true)]
    private ?string $successMessage = null;

    #[ORM\Column(name: 'consent_label', type: Types::TEXT, nullable: true)]
    private ?string $consentLabel = null;

    #[ORM\Column(name: 'privacy_policy_text', type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicyText = null;

    #[ORM\ManyToOne(targetEntity: ContactForm::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContactForm $form = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getSuccessMessage(): ?string
    {
        return $this->successMessage;
    }

    public function setSuccessMessage(?string $successMessage): self
    {
        $this->successMessage = $successMessage;

        return $this;
    }

    public function getConsentLabel(): ?string
    {
        return $this->consentLabel;
    }

    public function setConsentLabel(?string $consentLabel): self
    {
        $this->consentLabel = $consentLabel;

        return $this;
    }

    public function getPrivacyPolicyText(): ?string
    {
        return $this->privacyPolicyText;
    }

    public function setPrivacyPolicyText(?string $privacyPolicyText): self
    {
        $this->privacyPolicyText = $privacyPolicyText;

        return $this;
    }

    public function getForm(): ?ContactForm
    {
        return $this->form;
    }

    public function setForm(?ContactForm $form): self
    {
        $this->form = $form;

        return $this;
    }
}
