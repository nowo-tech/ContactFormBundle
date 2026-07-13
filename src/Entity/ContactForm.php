<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;

#[ORM\Entity(repositoryClass: ContactFormRepository::class)]
#[ORM\Table(name: 'nowo_contact_form')]
#[ORM\UniqueConstraint(name: 'uniq_contact_form_slug', columns: ['slug'])]
/**
 * Configurable multilingual contact form definition.
 */
class ContactForm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 120)]
    private string $slug = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'privacy_policy_url', length: 500, nullable: true)]
    private ?string $privacyPolicyUrl = null;

    #[ORM\Column(name: 'retention_days', options: ['default' => 365])]
    private int $retentionDays = 365;

    #[ORM\Column(name: 'require_consent', options: ['default' => false])]
    private bool $requireConsent = false;

    #[ORM\Column(name: 'notification_email', length: 255, nullable: true)]
    private ?string $notificationEmail = null;

    /** @var Collection<int, ContactFormTranslation> */
    #[ORM\OneToMany(targetEntity: ContactFormTranslation::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    /** @var Collection<int, ContactFormField> */
    #[ORM\OneToMany(targetEntity: ContactFormField::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $fields;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->fields       = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getPrivacyPolicyUrl(): ?string
    {
        return $this->privacyPolicyUrl;
    }

    public function setPrivacyPolicyUrl(?string $privacyPolicyUrl): self
    {
        $this->privacyPolicyUrl = $privacyPolicyUrl;

        return $this;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): self
    {
        $this->retentionDays = $retentionDays;

        return $this;
    }

    public function isRequireConsent(): bool
    {
        return $this->requireConsent;
    }

    public function setRequireConsent(bool $requireConsent): self
    {
        $this->requireConsent = $requireConsent;

        return $this;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->notificationEmail;
    }

    public function setNotificationEmail(?string $notificationEmail): self
    {
        $this->notificationEmail = $notificationEmail;

        return $this;
    }

    /**
     * @return Collection<int, ContactFormTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ContactFormTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setForm($this);
        }

        return $this;
    }

    public function removeTranslation(ContactFormTranslation $translation): self
    {
        if ($this->translations->removeElement($translation) && $translation->getForm() === $this) {
            $translation->setForm(null);
        }

        return $this;
    }

    public function findTranslation(string $locale): ?ContactFormTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getTranslationForLocale(string $locale, string $fallbackLocale = 'en'): ContactFormTranslation
    {
        $translation = $this->findTranslation($locale);

        if ($translation instanceof ContactFormTranslation) {
            return $translation;
        }

        $fallback = $this->findTranslation($fallbackLocale);

        if ($fallback instanceof ContactFormTranslation) {
            return $fallback;
        }

        $first = $this->translations->first();

        if ($first instanceof ContactFormTranslation) {
            return $first;
        }

        return (new ContactFormTranslation())->setLocale($locale);
    }

    /**
     * @return Collection<int, ContactFormField>
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(ContactFormField $field): self
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setForm($this);
        }

        return $this;
    }

    public function removeField(ContactFormField $field): self
    {
        if ($this->fields->removeElement($field) && $field->getForm() === $this) {
            $field->setForm(null);
        }

        return $this;
    }
}
