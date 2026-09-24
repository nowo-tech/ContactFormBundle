<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;

/**
 * @extends WorkerSafeServiceEntityRepository<ContactFormField>
 */
class ContactFormFieldRepository extends WorkerSafeServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFormField::class);
    }

    /**
     * Refreshes already managed fields and their translations (see ContactFormRepository::findOneEnabledBySlug()).
     *
     * @return list<ContactFormField>
     */
    public function findByFormOrdered(ContactForm $form): array
    {
        /** @var list<ContactFormField> $fields */
        $fields = $this->createQueryBuilder('f')
            ->leftJoin('f.translations', 't')
            ->addSelect('t')
            ->andWhere('f.form = :form')
            ->setParameter('form', $form)
            ->orderBy('f.sortOrder', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $fields;
    }
}
