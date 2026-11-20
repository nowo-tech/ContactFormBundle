<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Doctrine\ClientReferenceCollationListener;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function dirname;
use function implode;
use function ksort;
use function preg_match;
use function sort;

use const PHP_VERSION_ID;

/**
 * LI-CF-L2: `client_reference` compares exactly on MySQL / MariaDB (binary, no-pad collation in the generated schema);
 * PostgreSQL and SQLite keep their default (already exact) collation (PostgreSQL: see testCollationPerPlatformFamily;
 * its schema tool needs a live connection).
 */
#[CoversClass(ClientReferenceCollationListener::class)]
final class ClientReferenceCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL 8.0' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.0.36'], 'utf8mb4_0900_bin'];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], 'utf8mb4_0900_bin'];
        yield 'MariaDB 11' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.2-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('platforms')]
    public function testGeneratedColumnCollation(array $params, ?string $collation): void
    {
        $sql = $this->createSql($params);

        self::assertSame(1, preg_match('/client_reference[^,]*/', $sql, $match), $sql);
        if ($collation === null) {
            self::assertStringNotContainsString('COLLATE', $match[0]);

            return;
        }

        self::assertStringContainsString('CHARACTER SET utf8mb4', $match[0]);
        self::assertStringContainsString($collation, $match[0]);
        // Only this column: the other text columns keep the table default.
        self::assertSame(1, preg_match_all('/_bin/', $sql));
    }

    public function testCollationPerPlatformFamily(): void
    {
        self::assertSame('utf8mb4_0900_bin', ClientReferenceCollationListener::collationFor(new MySQL80Platform()));
        self::assertSame('utf8mb4_nopad_bin', ClientReferenceCollationListener::collationFor(new MariaDBPlatform()));
        self::assertSame('utf8mb4_bin', ClientReferenceCollationListener::collationFor(new MySQLPlatform()), 'MySQL 5.7: binary, PAD SPACE');
        self::assertNull(ClientReferenceCollationListener::collationFor(new PostgreSQLPlatform()));
        self::assertNull(ClientReferenceCollationListener::collationFor(new SQLitePlatform()));
    }

    public function testCollateWithAndWithoutTableEditor(): void
    {
        $table = new Table('t');
        $table->addColumn('client_reference', 'string', ['length' => 64, 'notnull' => false]);

        $edited = ClientReferenceCollationListener::collate($table, 'client_reference', 'utf8mb4_0900_bin', true);
        self::assertInstanceOf(Table::class, $edited);
        self::assertSame('utf8mb4_0900_bin', $edited->getColumn('client_reference')->getPlatformOption('collation'));

        // Older DBAL (no table editor): the column is changed in place.
        self::assertNull(ClientReferenceCollationListener::collate($table, 'client_reference', 'utf8mb4_bin', false));
        self::assertSame('utf8mb4_bin', $table->getColumn('client_reference')->getPlatformOption('collation'));
        self::assertSame('utf8mb4', $table->getColumn('client_reference')->getPlatformOption('charset'));

        self::assertNull(ClientReferenceCollationListener::collate($table, '', 'utf8mb4_bin', true));
    }

    /**
     * 1.13: without a fixed platform, the schema tool's own connection decides (the resolver argument is unused).
     */
    public function testWithoutAFixedPlatformTheSchemaToolsConnectionDecides(): void
    {
        $sql = $this->createSql(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.36'], new ClientReferenceCollationListener());

        self::assertStringContainsString('utf8mb4_0900_bin', $sql);
    }

    /**
     * Audit XH-1: the generated schema keeps every foreign key and index of every bundle table, as without the listener
     * (1.7.0–1.12.1 lost nowo_contact_submission.form_id → nowo_contact_form and its index on DBAL 4.5+).
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('platforms')]
    public function testEveryForeignKeyAndIndexIsKept(array $params, ?string $collation): void
    {
        $with    = self::shape($this->schema($params, true));
        $without = self::shape($this->schema($params, false));

        self::assertSame($without, $with);
        self::assertContains('form_id->nowo_contact_form(id) CASCADE', $with['nowo_contact_submission']['fks']);
        self::assertNotSame([], $with['nowo_contact_submission']['indexes']);
        self::assertSame($collation, $this->schema($params, true)->getTable('nowo_contact_submission')->getColumn('client_reference')->getPlatformOptions()['collation'] ?? null);
    }

    public function testAManagerWithoutTheBundleEntitiesIsLeftAlone(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/Integration/EntityManager/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $eventManager = new EventManager();
        $eventManager->addEventListener(ToolEvents::postGenerateSchema, new ClientReferenceCollationListener());
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], $config), $config, $eventManager);

        $schema = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

        // array_values(): DBAL 3 keys the tables by name.
        // @phpstan-ignore arrayValues.list
        self::assertSame(['host_note'], array_map(static fn (Table $table): string => $table->getName(), array_values($schema->getTables())));
    }

    public function testTheFormerTableEventDoesNothing(): void
    {
        $table = new Table('nowo_contact_submission');
        $table->addColumn('client_reference', 'string', ['length' => 64, 'notnull' => false]);
        $args = new GenerateSchemaTableEventArgs(new ClassMetadata(ContactSubmission::class), new Schema(), $table);

        (new ClientReferenceCollationListener(null, new MySQL80Platform()))->postGenerateSchemaTable($args);

        self::assertSame($table, $args->getClassTable());
        self::assertSame([], $table->getColumn('client_reference')->getPlatformOptions());
    }

    /**
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    private static function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = ($index->isUnique() ? 'unique ' : '') . implode(',', $index->getColumns());
            }
            sort($fks);
            sort($indexes);
            $shape[$table->getName()] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function schema(array $params, bool $listener): Schema
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $eventManager = new EventManager();
        if ($listener) {
            $eventManager->addEventListener(ToolEvents::postGenerateSchema, new ClientReferenceCollationListener());
        }
        $em = new EntityManager(DriverManager::getConnection($params, $config), $config, $eventManager);

        return (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createSql(array $params, ?ClientReferenceCollationListener $listener = null): string
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $eventManager = new EventManager();
        $em           = new EntityManager(DriverManager::getConnection($params, $config), $config, $eventManager);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $eventManager->addEventListener(ToolEvents::postGenerateSchema, $listener ?? new ClientReferenceCollationListener(new ContactFormEntityManagerResolver($registry)));

        $sql = (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        self::assertNotSame([], $sql);
        self::assertTrue($em->getMetadataFactory()->hasMetadataFor(ContactSubmission::class));

        return implode(";\n", $sql);
    }
}
