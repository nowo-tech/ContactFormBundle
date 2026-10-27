<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LazyCriteriaCollection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Repository\ContactFormFieldTranslationRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactFormTranslationRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionValueRepository;
use Nowo\ContactFormBundle\Repository\WorkerSafeServiceEntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerSafeServiceEntityRepository::class)]
#[CoversClass(ContactFormRepository::class)]
#[CoversClass(ContactFormTranslationRepository::class)]
#[CoversClass(ContactFormFieldTranslationRepository::class)]
#[CoversClass(ContactSubmissionValueRepository::class)]
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

    public function testEveryRepositoryReadsTheCurrentManager(): void
    {
        $metadata = new ClassMetadata(ContactForm::class);
        $manager  = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => match ($class) {
            ContactFormTranslation::class      => new ClassMetadata(ContactFormTranslation::class),
            ContactFormFieldTranslation::class => new ClassMetadata(ContactFormFieldTranslation::class),
            ContactSubmissionValue::class      => new ClassMetadata(ContactSubmissionValue::class),
            default                            => $metadata,
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        $repository = new class($registry) extends ContactFormRepository {
            public function manager(): EntityManagerInterface
            {
                return $this->getEntityManager();
            }

            public function entityName(): string
            {
                return $this->getEntityName();
            }

            /**
             * @return ClassMetadata<ContactForm>
             */
            public function metadata(): ClassMetadata
            {
                return $this->getClassMetadata();
            }
        };

        self::assertSame($manager, $repository->manager());
        self::assertSame(ContactForm::class, $repository->entityName());
        self::assertSame($metadata, $repository->metadata());
        self::assertSame(['f' => ContactForm::class], $repository->createResultSetMappingBuilder('f')->aliasMap);

        $persister  = $this->createStub(EntityPersister::class);
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getEntityPersister')->willReturn($persister);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        self::assertInstanceOf(LazyCriteriaCollection::class, $repository->matching(new Criteria()));

        self::assertSame(ContactFormTranslation::class, (new ContactFormTranslationRepository($registry))->getClassName());
        self::assertSame(ContactFormFieldTranslation::class, (new ContactFormFieldTranslationRepository($registry))->getClassName());
        self::assertSame(ContactSubmissionValue::class, (new ContactSubmissionValueRepository($registry))->getClassName());
    }

    public function testMissingManagerFailsLoudly(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager maps ' . ContactForm::class);

        (new ContactFormRepository($registry))->count();
    }
}
