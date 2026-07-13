<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;

/**
 * @extends ServiceEntityRepository<ContactFormFieldTranslation>
 */
class ContactFormFieldTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFormFieldTranslation::class);
    }
}
