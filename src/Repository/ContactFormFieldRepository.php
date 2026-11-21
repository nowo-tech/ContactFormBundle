<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\Doctrine\ContactFormWrites;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use SortDirection;

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
     * Saves the admin edit of an existing form field in one transaction (1.12, REQ-RUNTIME-003 rules 1 and 4).
     *
     * The row version is checked and advanced first (compare-and-swap, which also takes the row lock), then the unit
     * of work is flushed (the form field and its translations). A form field saved by someone else since the page was opened
     * throws {@see StaleVersionException} and nothing is written; a lock conflict is thrown as `TransientStorageFailure`
     * (409 + CONFLICT_001 on the admin route). The manager is never cleared or reset.
     *
     * @param ContactFormField $field The edited form field (managed by `$entityManager`, with the form's changes)
     * @param EntityManagerInterface $entityManager The manager of the bundle entities
     * @param int $expectedVersion The row version of the edited page (the form's hidden token)
     *
     * @throws StaleVersionException when `$expectedVersion` is no longer the row's version
     */
    public function saveEdit(ContactFormField $field, EntityManagerInterface $entityManager, int $expectedVersion): void
    {
        DoctrineTransactionRunner::forConnection($entityManager->getConnection())->run(static function () use ($field, $entityManager, $expectedVersion): void {
            RowVersionGuard::advance($entityManager, $field, $expectedVersion);
            ContactFormWrites::assertOnlyBundleEntities($entityManager);
            $entityManager->flush();
        });
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
            ->orderBy('f.sortOrder', SortDirection::Ascending)
            ->addOrderBy('f.id', SortDirection::Ascending)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $fields;
    }
}
