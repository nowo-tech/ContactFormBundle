<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Repository\ContactSubmissionValueRepository;

#[ORM\Entity(repositoryClass: ContactSubmissionValueRepository::class)]
#[ORM\Table(name: 'nowo_contact_submission_value')]
/**
 * Single field value captured in a contact submission.
 */
class ContactSubmissionValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(name: 'field_name', length: 120)]
    private string $fieldName = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $value = '';

    #[ORM\ManyToOne(targetEntity: ContactSubmission::class, inversedBy: 'values')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContactSubmission $submission = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function setFieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getSubmission(): ?ContactSubmission
    {
        return $this->submission;
    }

    public function setSubmission(?ContactSubmission $submission): self
    {
        $this->submission = $submission;

        return $this;
    }
}
