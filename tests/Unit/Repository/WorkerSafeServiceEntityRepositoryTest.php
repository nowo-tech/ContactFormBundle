<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\WorkerSafeServiceEntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerSafeServiceEntityRepository::class)]
#[CoversClass(ContactFormRepository::class)]
final class WorkerSafeServiceEntityRepositoryTest extends TestCase
{
    public function testCreateQueryBuilderAlwaysUsesCurrentManagerFromRegistry(): void
    {
        $metadata       = $this->createMock(ClassMetadata::class);
        $metadata->name = ContactForm::class;

        $firstManager = $this->createMock(EntityManagerInterface::class);
        $firstManager->method('getClassMetadata')->with(ContactForm::class)->willReturn($metadata);
        $firstManager->expects(self::once())->method('createQueryBuilder')->willReturn($this->queryBuilderMock());

        $secondManager = $this->createMock(EntityManagerInterface::class);
        $secondManager->method('getClassMetadata')->with(ContactForm::class)->willReturn($metadata);
        $secondManager->expects(self::once())->method('createQueryBuilder')->willReturn($this->queryBuilderMock());

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::exactly(2))
            ->method('getManagerForClass')
            ->with(ContactForm::class)
            ->willReturnOnConsecutiveCalls($firstManager, $secondManager);

        $repository = new ContactFormRepository($registry);
        $repository->createQueryBuilder('f');
        $repository->createQueryBuilder('f');
    }

    private function queryBuilderMock(): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();

        return $queryBuilder;
    }
}
