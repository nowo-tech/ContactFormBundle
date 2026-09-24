<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormEntityManagerResolver::class)]
final class ContactFormEntityManagerResolverTest extends TestCase
{
    public function testGetReturnsOpenManagerWithoutReset(): void
    {
        $entityManager = $this->createEntityManager(true);
        $registry      = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(ContactForm::class)->willReturn($entityManager);
        $registry->expects(self::never())->method('resetManager');

        self::assertSame($entityManager, (new ContactFormEntityManagerResolver($registry))->get());
    }

    public function testGetResetsClosedManagerByItsRegistryName(): void
    {
        $closed   = $this->createEntityManager(false);
        $fresh    = $this->createEntityManager(true);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager', 'default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturnMap([
            ['other', $this->createEntityManager(true)],
            ['default', $closed],
        ]);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($fresh);

        self::assertSame($fresh, (new ContactFormEntityManagerResolver($registry))->get());
    }

    public function testGetKeepsClosedManagerWhenItCannotBeReset(): void
    {
        $closed   = $this->createEntityManager(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($closed);
        $registry->method('resetManager')->willReturn($this->createMock(ObjectManager::class));

        self::assertSame($closed, (new ContactFormEntityManagerResolver($registry))->get());
    }

    public function testGetKeepsClosedManagerUnknownToRegistry(): void
    {
        $closed   = $this->createEntityManager(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->expects(self::never())->method('resetManager');

        self::assertSame($closed, (new ContactFormEntityManagerResolver($registry))->get());
    }

    public function testResetClosedOnlyResetsWhenClosed(): void
    {
        $open     = $this->createEntityManager(true);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($open);
        $registry->expects(self::never())->method('resetManager');

        (new ContactFormEntityManagerResolver($registry))->resetClosed();

        $closed   = $this->createEntityManager(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($closed);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($this->createEntityManager(true));

        (new ContactFormEntityManagerResolver($registry))->resetClosed();
    }

    public function testThrowsWhenNoOrmManagerMapsTheBundleEntities(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);

        (new ContactFormEntityManagerResolver($registry))->get();
    }

    private function createEntityManager(bool $open): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn($open);

        return $entityManager;
    }
}
