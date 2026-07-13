<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;

/**
 * @extends ServiceEntityRepository<ContactSubmission>
 */
class ContactSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactSubmission::class);
    }

    /**
     * @return list<ContactSubmission>
     */
    public function findByFormOrdered(ContactForm $form, int $limit = 100): array
    {
        return $this->findBy(['form' => $form], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * @return list<int>
     */
    public function findExpiredIdsByForm(ContactForm $form, DateTimeImmutable $threshold): array
    {
        /** @var list<int> $ids */
        $ids = $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.form = :form')
            ->andWhere('s.createdAt < :threshold')
            ->setParameter('form', $form)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn ($id): int => (int) $id, $ids);
    }
}
