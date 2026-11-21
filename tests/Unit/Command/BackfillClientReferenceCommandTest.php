<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Command\BackfillClientReferenceCommand;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function dirname;
use function preg_replace;

use const PHP_VERSION_ID;

/**
 * The 2.0 upgrade step (owner decision Q-W4-2) on SQLite: a 1.x schema (with the INTEGER `client_id` column) gets every
 * `client_id` copied into an empty `client_reference`, rows with a reference are never overwritten, a second run
 * changes nothing, `--dry-run` only counts, and a 2.0 schema (no `client_id`) is "nothing to backfill". The real
 * MySQL / MariaDB / PostgreSQL runs are in Integration\Database\ClientIdBackfillDatabaseTest.
 */
#[CoversClass(BackfillClientReferenceCommand::class)]
final class BackfillClientReferenceCommandTest extends TestCase
{
    public function testCopiesEveryLegacyIdIntoAnEmptyReferenceOnlyOnce(): void
    {
        $entityManager = $this->legacySchema();
        $connection    = $entityManager->getConnection();
        $this->insertRows($entityManager);
        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($entityManager)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('3 submission(s) would get their client_reference from client_id.', $this->display($tester));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission WHERE client_reference IS NULL AND client_id IS NOT NULL'), 'the dry run changes nothing');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('3 submission(s) backfilled.', $this->display($tester));
        self::assertSame($this->expectedRows(), $this->rows($connection));

        // Idempotent: a second run finds nothing and changes nothing.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 submission(s) backfilled.', $this->display($tester));
        self::assertSame($this->expectedRows(), $this->rows($connection));
    }

    public function testASchemaWithoutTheLegacyColumnHasNothingToBackfill(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($entityManager)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The table "nowo_contact_submission" has no "client_id" column: nothing to backfill.', $this->display($tester));
    }

    public function testAMissingTableHasNothingToBackfill(): void
    {
        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($this->entityManager())));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('nothing to backfill', $this->display($tester));
    }

    public function testALockConflictFailsTheRunWithARunAgainMessage(): void
    {
        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($this->failingUpdate(
            new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
            }, null),
        ))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('A lock conflict with another write stopped the backfill; nothing was changed. Run the command again.', $this->display($tester));
    }

    public function testAnyOtherFailureIsRethrown(): void
    {
        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($this->failingUpdate(new RuntimeException('disk full')))));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk full');
        $tester->execute([]);
    }

    public function testAnotherFailureOfTheColumnProbeIsRethrown(): void
    {
        $real    = $this->legacySchema();
        $failure = new DriverException(new class('disk I/O error', 'HY000', 10) extends AbstractException {
        }, null);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($real->getConnection()->getDatabasePlatform());
        $connection->method('fetchOne')->willThrowException($failure);
        $connection->expects(self::never())->method('executeStatement');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnCallback(static fn (string $class): mixed => $real->getClassMetadata($class));
        $entityManager->method('getConfiguration')->willReturn($real->getConfiguration());
        $entityManager->method('isOpen')->willReturn(true);

        $this->expectExceptionObject($failure);
        (new CommandTester(new BackfillClientReferenceCommand($this->resolver($entityManager))))->execute([]);
    }

    public function testRowsLeftAfterTheUpdateFailTheRun(): void
    {
        $entityManager = $this->legacySchema();
        $connection    = $entityManager->getConnection();
        $this->insertRows($entityManager);
        // A write between the UPDATE and the check (a 1.x process still running) leaves a row to copy.
        $connection->executeStatement(
            "CREATE TRIGGER late_legacy_write AFTER UPDATE ON nowo_contact_submission WHEN NEW.id = 1 BEGIN INSERT INTO nowo_contact_submission (form_id, locale, created_at, client_id, client_reference) VALUES (1, 'en', '2026-01-01 00:00:00', 99, NULL); END",
        );

        $tester = new CommandTester(new BackfillClientReferenceCommand($this->resolver($entityManager)));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('1 submission(s) still have a client_id without a client_reference. Run the command again before dropping the column.', $this->display($tester));

        $connection->executeStatement('DROP TRIGGER late_legacy_write');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('99', $connection->fetchOne('SELECT client_reference FROM nowo_contact_submission WHERE client_id = 99'));
    }

    /**
     * A 1.13 table: the 2.0 schema plus the INTEGER `client_id` column 2.0 no longer maps.
     */
    private function legacySchema(): EntityManagerInterface
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $entityManager->getConnection()->executeStatement('ALTER TABLE nowo_contact_submission ADD COLUMN client_id INTEGER DEFAULT NULL');

        return $entityManager;
    }

    private function insertRows(EntityManagerInterface $entityManager): void
    {
        $form = (new ContactForm())->setName('Support')->setSlug('support');
        $entityManager->persist($form);
        $entityManager->flush();
        self::assertSame(1, $form->getId());
        $connection = $entityManager->getConnection();
        foreach ([
            [1, 42, null],          // written before 1.7: the client is only in client_id
            [2, -7, null],          // a negative id is copied as written
            [3, 2147483647, null],  // the INTEGER maximum
            [4, 5, 'uuid-ref'],     // already has a reference: never overwritten
            [5, null, 'only-ref'],  // a 1.7+ row with a string id
            [6, null, null],        // anonymous
        ] as [$id, $clientId, $reference]) {
            $connection->insert('nowo_contact_submission', ['id' => $id, 'form_id' => 1, 'locale' => 'en', 'created_at' => '2026-01-01 00:00:00', 'client_id' => $clientId, 'client_reference' => $reference]);
        }
    }

    /**
     * @return list<array{int, string|null}>
     */
    private function expectedRows(): array
    {
        return [[1, '42'], [2, '-7'], [3, '2147483647'], [4, 'uuid-ref'], [5, 'only-ref'], [6, null]];
    }

    /**
     * @return list<array{int, string|null}>
     */
    private function rows(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->fetchAllAssociative('SELECT id, client_reference FROM nowo_contact_submission ORDER BY id') as $row) {
            $rows[] = [(int) $row['id'], $row['client_reference'] === null ? null : (string) $row['client_reference']];
        }

        return $rows;
    }

    /**
     * A SQLite manager whose UPDATE fails with the given exception (after the legacy column was found and counted).
     */
    private function failingUpdate(Throwable $failure): EntityManagerInterface
    {
        $real = $this->legacySchema();
        $this->insertRows($real);

        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([$real->getConnection()->getParams(), $real->getConnection()->getDriver(), $real->getConnection()->getConfiguration()])
            ->onlyMethods(['executeStatement', 'fetchOne', 'getDatabasePlatform'])
            ->getMock();
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql): mixed => $real->getConnection()->fetchOne($sql));
        $connection->method('getDatabasePlatform')->willReturn($real->getConnection()->getDatabasePlatform());
        $connection->method('executeStatement')->willThrowException($failure);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnCallback(static fn (string $class): mixed => $real->getClassMetadata($class));
        $entityManager->method('getConfiguration')->willReturn($real->getConfiguration());
        $entityManager->method('isOpen')->willReturn(true);

        return $entityManager;
    }

    private function resolver(EntityManagerInterface $entityManager): ContactFormEntityManagerResolver
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (string $class): ?EntityManagerInterface => $class === ContactForm::class ? $entityManager : null);

        return new ContactFormEntityManagerResolver($registry);
    }

    /** The command output on one line (SymfonyStyle wraps long messages). */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    }
}
