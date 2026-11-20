<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

use function max;

/**
 * @extends WorkerSafeServiceEntityRepository<ContactForm>
 */
class ContactFormRepository extends WorkerSafeServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactForm::class);
    }

    /**
     * Saves the admin edit of an existing contact form in one transaction (1.12, REQ-RUNTIME-003 rules 1 and 4).
     *
     * The row version is checked and advanced first (compare-and-swap, which also takes the row lock), then the unit
     * of work is flushed (the contact form and its translations). A contact form saved by someone else since the page was opened
     * throws {@see StaleVersionException} and nothing is written; a lock conflict is thrown as `TransientStorageFailure`
     * (409 + CONFLICT_001 on the admin route). The manager is never cleared or reset.
     *
     * @param ContactForm $form The edited contact form (managed by `$entityManager`, with the form's changes)
     * @param EntityManagerInterface $entityManager The manager of the bundle entities
     * @param int $expectedVersion The row version of the edited page (the form's hidden token)
     *
     * @throws StaleVersionException when `$expectedVersion` is no longer the row's version
     */
    public function saveEdit(ContactForm $form, EntityManagerInterface $entityManager, int $expectedVersion): void
    {
        DoctrineTransactionRunner::forConnection($entityManager->getConnection())->run(static function () use ($form, $entityManager, $expectedVersion): void {
            RowVersionGuard::advance($entityManager, $form, $expectedVersion);
            $entityManager->flush();
        });
    }

    /**
     * Refreshes an already managed form and its translations so admin edits made in another
     * worker are visible when the identity map survives between requests (worker mode without
     * kernel reset). The slug is unique, so no row limit is needed with the collection join.
     */
    public function findOneEnabledBySlug(string $slug): ?ContactForm
    {
        /** @var ContactForm|null $form */
        $form = $this->createQueryBuilder('f')
            ->leftJoin('f.translations', 't')
            ->addSelect('t')
            ->andWhere('f.slug = :slug')
            ->andWhere('f.enabled = :enabled')
            ->setParameter('slug', $slug)
            ->setParameter('enabled', true)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $form;
    }

    /**
     * @return list<ContactForm>
     */
    public function findOrderedPage(int $page, int $pageSize): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, $pageSize);

        /** @var list<ContactForm> $forms */
        $forms = $this->findBy([], ['name' => 'ASC'], $pageSize, ($page - 1) * $pageSize);

        return $forms;
    }
}
