<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryProxy;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use SortDirection;

/**
 * ServiceEntityRepository that always uses the current EntityManager from the registry.
 *
 * DoctrineBundle's ORM 3 {@see ServiceEntityRepositoryProxy}
 * caches the inner repository (and its manager) after the first call. After
 * {@see ManagerRegistry::resetManager()} — required when a flush fails and the kernel is not
 * reset between FrankenPHP worker requests — that cache would keep a closed manager. This base
 * re-resolves the manager on every call.
 *
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class WorkerSafeServiceEntityRepository extends ServiceEntityRepository
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly string $entityClass,
    ) {
        parent::__construct($managerRegistry, $entityClass);
    }

    protected function currentEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass($this->entityClass);

        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager maps ' . $this->entityClass . '.');
        }

        return $manager;
    }

    /**
     * @return EntityRepository<T>
     */
    private function freshRepository(): EntityRepository
    {
        $entityManager = $this->currentEntityManager();

        /** @var ClassMetadata<T> $classMetadata */
        $classMetadata = $entityManager->getClassMetadata($this->entityClass);

        return new EntityRepository($entityManager, $classMetadata);
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->freshRepository()->createQueryBuilder($alias, $indexBy);
    }

    public function createResultSetMappingBuilder(string $alias): ResultSetMappingBuilder
    {
        return $this->freshRepository()->createResultSetMappingBuilder($alias);
    }

    public function find(mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object
    {
        return $this->freshRepository()->find($id, $lockMode ?? LockMode::NONE, $lockVersion);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'asc'|'DESC'|'desc'|SortDirection>|null $orderBy
     *
     * @return list<T>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        /** @var list<T> $result */
        // ORM 3.7 accepts SortDirection at runtime (BasicEntityPersister); the inherited phpdoc still lists strings only.
        $result = $this->freshRepository()->findBy($criteria, $orderBy, $limit, $offset); // @phpstan-ignore argument.type

        return $result;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'asc'|'DESC'|'desc'|SortDirection>|null $orderBy
     *
     * @return T|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        /** @var T|null $result */
        // ORM 3.7 accepts SortDirection at runtime (BasicEntityPersister); the inherited phpdoc still lists strings only.
        $result = $this->freshRepository()->findOneBy($criteria, $orderBy); // @phpstan-ignore argument.type

        return $result;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return $this->freshRepository()->count($criteria);
    }

    /**
     * @return AbstractLazyCollection<int, T>&Selectable<int, T>
     */
    public function matching(Criteria $criteria): AbstractLazyCollection&Selectable
    {
        return $this->freshRepository()->matching($criteria);
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->currentEntityManager();
    }

    protected function getEntityName(): string
    {
        return $this->entityClass;
    }

    protected function getClassMetadata(): ClassMetadata
    {
        /** @var ClassMetadata<T> $metadata */
        $metadata = $this->currentEntityManager()->getClassMetadata($this->entityClass);

        return $metadata;
    }
}
