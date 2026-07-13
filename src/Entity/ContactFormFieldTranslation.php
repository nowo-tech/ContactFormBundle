<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Repository\ContactFormFieldTranslationRepository;

#[ORM\Entity(repositoryClass: ContactFormFieldTranslationRepository::class)]
#[ORM\Table(name: 'nowo_contact_form_field_translation')]
#[ORM\UniqueConstraint(name: 'uniq_contact_form_field_translation_locale', columns: ['field_id', 'locale'])]
/**
 * Locale-specific labels for a contact form field.
 */
class ContactFormFieldTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $locale = 'en';

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeholder = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'select_options', type: Types::JSON, nullable: true)]
    private ?array $selectOptions = null;

    #[ORM\ManyToOne(targetEntity: ContactFormField::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContactFormField $field = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = ($locale !== null && $locale !== '') ? $locale : 'en';

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        if ($label !== null) {
            $this->label = $label;
        }

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function setHelp(?string $help): self
    {
        $this->help = $help;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getSelectOptions(): ?array
    {
        return $this->selectOptions;
    }

    /**
     * @param list<string>|null $selectOptions
     */
    public function setSelectOptions(?array $selectOptions): self
    {
        $this->selectOptions = $selectOptions;

        return $this;
    }

    public function getField(): ?ContactFormField
    {
        return $this->field;
    }

    public function setField(?ContactFormField $field): self
    {
        $this->field = $field;

        return $this;
    }
}
