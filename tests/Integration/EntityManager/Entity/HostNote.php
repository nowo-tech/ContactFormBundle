<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity on the host's default manager: its pending changes must never be flushed or cleared by the bundle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $text,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
