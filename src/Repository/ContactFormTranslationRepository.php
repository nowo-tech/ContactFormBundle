<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;

/**
 * @extends WorkerSafeServiceEntityRepository<ContactFormTranslation>
 */
class ContactFormTranslationRepository extends WorkerSafeServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFormTranslation::class);
    }
}
