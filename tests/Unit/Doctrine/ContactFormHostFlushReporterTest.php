<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The enabled path (a real unit of work with a pending host entity) is covered by
 * tests/Integration/EntityManager/PrivateEntityManagerTest.php.
 */
#[CoversClass(ContactFormHostFlushReporter::class)]
final class ContactFormHostFlushReporterTest extends TestCase
{
    public function testDisabledInspectsNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getUnitOfWork');

        $reporter = new ContactFormHostFlushReporter();
        $reporter->beforeFlush($entityManager);
        $reporter->reset();
    }

    public function testThePrivateManagerIsNeverInspected(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManager');

        (new ContactFormHostFlushReporter(true))->beforeFlush(new PrivateEntityManager($registry));
    }
}
