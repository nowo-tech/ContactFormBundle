<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;

/**
 * @extends WorkerSafeServiceEntityRepository<ContactSubmissionValue>
 */
class ContactSubmissionValueRepository extends WorkerSafeServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactSubmissionValue::class);
    }
}
