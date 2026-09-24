<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;

/**
 * @extends WorkerSafeServiceEntityRepository<ContactFormFieldTranslation>
 */
class ContactFormFieldTranslationRepository extends WorkerSafeServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFormFieldTranslation::class);
    }
}
