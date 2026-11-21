<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager\LinkedEntity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ContactFormBundle\Entity\ContactForm;

/**
 * A host entity with a ManyToOne to a bundle entity (wave-1 lesson W1-2): the host writes it through its own manager,
 * pointing at the contact form with `$hostEm->getReference(ContactForm::class, $id)`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_form_link')]
class HostFormLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ContactForm::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ContactForm $form,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getForm(): ContactForm
    {
        return $this->form;
    }
}
