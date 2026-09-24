<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;

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
