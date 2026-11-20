<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use LogicException;

use function str_starts_with;

/**
 * A one-manager registry of the bundle entities for the second process of the concurrency tests (tests only).
 */
final readonly class FixedManagerRegistry implements ManagerRegistry
{
    private const ENTITY_NAMESPACE = 'Nowo\\ContactFormBundle\\Entity\\';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return ['default' => $this->entityManager->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        throw new LogicException('Not supported in tests.');
    }

    public function getManagerNames(): array
    {
        return ['default' => 'default'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        return str_starts_with($class, self::ENTITY_NAMESPACE) ? $this->entityManager : null;
    }
}
