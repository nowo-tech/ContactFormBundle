<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\VersionedRowInterface;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;

#[ORM\Entity(repositoryClass: ContactFormFieldRepository::class)]
#[ORM\Table(name: 'nowo_contact_form_field')]
#[ORM\UniqueConstraint(name: 'uniq_contact_form_field_name', columns: ['form_id', 'name'])]
/**
 * Customizable field definition belonging to a contact form.
 */
class ContactFormField implements VersionedRowInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    /**
     * Row version for the admin edits (REQ-RUNTIME-003 rule 4): advanced by one on every admin save, in the save's
     * transaction, and checked against the version the edit page was built from ({@see RowVersionGuard}).
     */
    #[ORM\Column(name: 'row_version', type: Types::INTEGER, options: ['default' => 1])]
    private int $rowVersion = 1;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: ContactFieldType::class)]
    private ContactFieldType $type = ContactFieldType::Text;

    #[ORM\Column(options: ['default' => false])]
    private bool $required = false;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    /** @var array<string, mixed>|list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    #[ORM\ManyToOne(targetEntity: ContactForm::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContactForm $form = null;

    /** @var Collection<int, ContactFormFieldTranslation> */
    #[ORM\OneToMany(targetEntity: ContactFormFieldTranslation::class, mappedBy: 'field', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    private ?string $flowStep = null;

    /**
     * Not persisted: the row version the wizard was started on (the first step carries it as a hidden field, the session
     * copy of the field keeps it to the last step), checked when the wizard saves an existing field.
     */
    private ?int $flowBaseVersion = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The row version this process last read or wrote (carried by the edit form as its base token).
     */
    public function getRowVersion(): int
    {
        return $this->rowVersion;
    }

    /**
     * Records the row version after the admin save advanced it (set by {@see RowVersionGuard}, not by forms).
     */
    public function setRowVersion(int $rowVersion): static
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->rowVersion = $rowVersion;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        if ($name !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->name = $name;
        }

        return $this;
    }

    public function getType(): ContactFieldType
    {
        return $this->type;
    }

    public function setType(?ContactFieldType $type): self
    {
        if ($type instanceof ContactFieldType) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->type = $type;
        }

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->required = $required;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): self
    {
        if ($sortOrder !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->sortOrder = $sortOrder;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>|list<string>|null
     */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed>|list<string>|null $options
     */
    public function setOptions(?array $options): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->options = $options;

        return $this;
    }

    public function getForm(): ?ContactForm
    {
        return $this->form;
    }

    public function setForm(?ContactForm $form): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->form = $form;

        return $this;
    }

    /**
     * @return Collection<int, ContactFormFieldTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ContactFormFieldTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setField($this);
        }

        return $this;
    }

    public function removeTranslation(ContactFormFieldTranslation $translation): self
    {
        if ($this->translations->removeElement($translation) && $translation->getField() === $this) {
            $translation->setField(null);
        }

        return $this;
    }

    public function findTranslation(string $locale): ?ContactFormFieldTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getTranslationForLocale(string $locale, string $fallbackLocale = 'en'): ContactFormFieldTranslation
    {
        $translation = $this->findTranslation($locale);

        if ($translation instanceof ContactFormFieldTranslation) {
            return $translation;
        }

        $fallback = $this->findTranslation($fallbackLocale);

        if ($fallback instanceof ContactFormFieldTranslation) {
            return $fallback;
        }

        $first = $this->translations->first();

        if ($first instanceof ContactFormFieldTranslation) {
            return $first;
        }

        return (new ContactFormFieldTranslation())->setLocale($locale);
    }

    public function getFlowStep(): ?string
    {
        return $this->flowStep;
    }

    public function setFlowStep(?string $flowStep): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->flowStep = $flowStep;

        return $this;
    }

    public function getFlowBaseVersion(): ?int
    {
        return $this->flowBaseVersion;
    }

    public function setFlowBaseVersion(?int $flowBaseVersion): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->flowBaseVersion = $flowBaseVersion;

        return $this;
    }
}
