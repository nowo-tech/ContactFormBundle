<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;

/**
 * @extends ServiceEntityRepository<ContactForm>
 */
class ContactFormRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactForm::class);
    }

    public function findOneEnabledBySlug(string $slug): ?ContactForm
    {
        return $this->findOneBy(['slug' => $slug, 'enabled' => true]);
    }
}
