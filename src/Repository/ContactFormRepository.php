<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;

use function max;

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
