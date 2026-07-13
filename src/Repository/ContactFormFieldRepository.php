<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;

/**
 * @extends ServiceEntityRepository<ContactFormField>
 */
class ContactFormFieldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFormField::class);
    }

    /**
     * @return list<ContactFormField>
     */
    public function findByFormOrdered(ContactForm $form): array
    {
        return $this->findBy(['form' => $form], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
