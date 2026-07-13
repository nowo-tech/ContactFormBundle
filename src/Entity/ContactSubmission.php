<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;

#[ORM\Entity(repositoryClass: ContactSubmissionRepository::class)]
#[ORM\Table(name: 'nowo_contact_submission')]
/**
 * Stored contact submission with GDPR metadata and optional client association.
 */
class ContactSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContactForm::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContactForm $form = null;

    #[ORM\Column(name: 'client_id', type: Types::INTEGER, nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(name: 'client_label', length: 255, nullable: true)]
    private ?string $clientLabel = null;

    #[ORM\Column(length: 10)]
    private string $locale = 'en';

    #[ORM\Column(name: 'ip_hash', length: 64, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(name: 'consent_given_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $consentGivenAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, ContactSubmissionValue> */
    #[ORM\OneToMany(targetEntity: ContactSubmissionValue::class, mappedBy: 'submission', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $values;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->values    = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(?int $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientLabel(): ?string
    {
        return $this->clientLabel;
    }

    public function setClientLabel(?string $clientLabel): self
    {
        $this->clientLabel = $clientLabel;

        return $this;
    }

    public function isAnonymous(): bool
    {
        return $this->clientId === null;
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

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): self
    {
        $this->ipHash = $ipHash;

        return $this;
    }

    public function getConsentGivenAt(): ?DateTimeImmutable
    {
        return $this->consentGivenAt;
    }

    public function setConsentGivenAt(?DateTimeImmutable $consentGivenAt): self
    {
        $this->consentGivenAt = $consentGivenAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, ContactSubmissionValue>
     */
    public function getValues(): Collection
    {
        return $this->values;
    }

    public function addValue(ContactSubmissionValue $value): self
    {
        if (!$this->values->contains($value)) {
            $this->values->add($value);
            $value->setSubmission($this);
        }

        return $this;
    }

    public function removeValue(ContactSubmissionValue $value): self
    {
        if ($this->values->removeElement($value) && $value->getSubmission() === $this) {
            $value->setSubmission(null);
        }

        return $this;
    }
}
