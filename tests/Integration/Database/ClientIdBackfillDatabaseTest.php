<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Command\BackfillClientReferenceCommand;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function dirname;
use function getenv;
use function implode;
use function is_string;
use function preg_match;
use function preg_replace;

use const PHP_VERSION_ID;

/**
 * The 2.0 upgrade of a 1.13 database (owner decision Q-W4-2) on SQLite and on real MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16 servers:
 *
 * - the 1.13 table is the 2.0 schema plus the nullable INTEGER `client_id` column: the only mapping difference between
 *   1.13.1 and 2.0 is that column (`ContactSubmission::$clientId` and its XML field);
 * - `nowo:contact-form:backfill-client-reference` copies every `client_id` into an empty `client_reference` (negative
 *   and 32-bit maximum ids included), never overwrites a reference, changes nothing on a second run;
 * - the schema diff of 2.0 against that table is the `client_id` drop and nothing else (on SQLite, the table rebuild
 *   SQLite needs to drop a column); applied after the backfill it keeps every row and every client reference, and the
 *   schema is then in sync.
 */
#[Group('database')]
#[CoversClass(BackfillClientReferenceCommand::class)]
final class ClientIdBackfillDatabaseTest extends TestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function tearDown(): void
    {
        $this->entityManager?->getConnection()->close();
        $this->entityManager = null;
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        foreach (['mysql' => 'CF_MYSQL_URL', 'mariadb' => 'CF_MARIADB_URL', 'postgres' => 'CF_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    #[DataProvider('databases')]
    public function testTheBackfillThenTheSchemaDropKeepsEveryClientLink(?string $url): void
    {
        $entityManager = $this->legacyDatabase($url);
        $connection    = $entityManager->getConnection();
        $formId        = $this->seed($entityManager);
        $tester        = new CommandTester(new BackfillClientReferenceCommand($this->resolver($entityManager)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('3 submission(s) would get their client_reference from client_id.', $this->display($tester));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('3 submission(s) backfilled.', $this->display($tester));
        self::assertSame($this->expected(), $this->references($entityManager));

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'idempotent');
        self::assertStringContainsString('0 submission(s) backfilled.', $this->display($tester));
        self::assertSame($this->expected(), $this->references($entityManager));

        // What doctrine:migrations:diff / doctrine:schema:update proposes: the client_id drop only.
        $tool    = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        $diff    = $tool->getUpdateSchemaSql($classes);
        self::assertNotSame([], $diff);
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // SQLite drops a column by rebuilding the table: only nowo_contact_submission is touched.
            foreach ($diff as $sql) {
                self::assertMatchesRegularExpression('/nowo_contact_submission/i', $sql, $sql);
            }
        } else {
            self::assertCount(1, $diff, implode("\n", $diff));
            self::assertSame(1, preg_match('/^ALTER TABLE nowo_contact_submission DROP (COLUMN )?client_id$/i', $diff[0]), $diff[0]);
        }

        foreach ($diff as $sql) {
            $connection->executeStatement($sql);
        }
        self::assertSame([], $tool->getUpdateSchemaSql($classes), 'in sync after the drop');
        self::assertSame($this->expected(), $this->references($entityManager), 'every row and client reference kept');
        self::assertSame($formId, (int) $connection->fetchOne('SELECT form_id FROM nowo_contact_submission WHERE client_reference = ?', ['42']));

        // After the drop the command has nothing to do.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('has no "client_id" column: nothing to backfill', $this->display($tester));
    }

    /**
     * Without the backfill, the drop loses the client link of the rows written before 1.7 (what UPGRADING warns about).
     */
    #[DataProvider('databases')]
    public function testTheDropWithoutTheBackfillLosesTheLegacyClientLinks(?string $url): void
    {
        $entityManager = $this->legacyDatabase($url);
        $this->seed($entityManager);

        foreach ((new SchemaTool($entityManager))->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()) as $sql) {
            $entityManager->getConnection()->executeStatement($sql);
        }

        self::assertSame([[1, null], [2, null], [3, null], [4, 'uuid-ref'], [5, 'only-ref'], [6, null]], $this->references($entityManager));
    }

    /**
     * A 1.13 database: the 2.0 schema plus the nullable INTEGER `client_id` column.
     */
    private function legacyDatabase(?string $url): EntityManagerInterface
    {
        if ($url === null) {
            self::markTestSkipped('Set CF_MYSQL_URL, CF_MARIADB_URL and CF_POSTGRES_URL to run the real-database tests.');
        }

        $params = $url === 'sqlite'
            ? ['driver' => 'pdo_sqlite', 'memory' => true]
            : (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        /** @phpstan-ignore argument.type (DsnParser output) */
        $entityManager       = new EntityManager(DriverManager::getConnection($params), $config);
        $this->entityManager = $entityManager;
        $tool                = new SchemaTool($entityManager);
        $metadata            = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropDatabase();
        $tool->createSchema($metadata);
        $entityManager->getConnection()->executeStatement('ALTER TABLE nowo_contact_submission ADD client_id INT DEFAULT NULL');

        return $entityManager;
    }

    private function seed(EntityManagerInterface $entityManager): int
    {
        $form = (new ContactForm())->setName('Support')->setSlug('support');
        $entityManager->persist($form);
        $entityManager->flush();
        $formId = (int) $form->getId();

        foreach ([
            [1, 42, null],          // written before 1.7: the client is only in client_id
            [2, -7, null],          // negative
            [3, 2147483647, null],  // the INTEGER maximum
            [4, 5, 'uuid-ref'],     // already has a reference: never overwritten
            [5, null, 'only-ref'],  // a 1.7+ row with a string id
            [6, null, null],        // anonymous
        ] as [$id, $clientId, $reference]) {
            $entityManager->getConnection()->insert('nowo_contact_submission', [
                'id'               => $id,
                'form_id'          => $formId,
                'locale'           => 'en',
                'created_at'       => '2026-01-01 00:00:00',
                'client_id'        => $clientId,
                'client_reference' => $reference,
            ]);
        }

        return $formId;
    }

    /**
     * @return list<array{int, string|null}>
     */
    private function expected(): array
    {
        return [[1, '42'], [2, '-7'], [3, '2147483647'], [4, 'uuid-ref'], [5, 'only-ref'], [6, null]];
    }

    /**
     * @return list<array{int, string|null}>
     */
    private function references(EntityManagerInterface $entityManager): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['id'], $row['client_reference'] === null ? null : (string) $row['client_reference']],
            $entityManager->getConnection()->fetchAllAssociative('SELECT id, client_reference FROM nowo_contact_submission ORDER BY id'),
        );
    }

    /** The command output on one line (SymfonyStyle wraps long messages). */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function resolver(EntityManagerInterface $entityManager): ContactFormEntityManagerResolver
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (string $class): ?EntityManagerInterface => $class === ContactForm::class ? $entityManager : null);

        return new ContactFormEntityManagerResolver($registry);
    }
}
