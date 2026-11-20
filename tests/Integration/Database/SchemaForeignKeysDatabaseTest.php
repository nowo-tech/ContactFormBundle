<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\ContactFormBundle\Doctrine\ClientReferenceCollationListener;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function getenv;
use function implode;
use function is_string;
use function ksort;
use function method_exists;
use function sort;
use function str_replace;
use function strtolower;

use const PHP_VERSION_ID;

/**
 * Audit XH-1 (1.13) on real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 servers: with the client_reference collation
 * listener, the generated schema keeps every foreign key and index of every bundle table (the same as without it),
 * the created database has nowo_contact_submission.form_id → nowo_contact_form ON DELETE CASCADE, and deleting a form
 * deletes its submissions and their values (no orphaned personal data). 1.7.0–1.12.1 lost that foreign key and its
 * index on MySQL / MariaDB with DBAL 4.5+.
 */
#[Group('database')]
final class SchemaForeignKeysDatabaseTest extends TestCase
{
    private ?EntityManagerInterface $manager = null;

    protected function tearDown(): void
    {
        $this->manager?->getConnection()->close();
        $this->manager = null;
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        foreach (['mysql' => 'CF_MYSQL_URL', 'mariadb' => 'CF_MARIADB_URL', 'postgres' => 'CF_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    #[DataProvider('databases')]
    public function testTheGeneratedSchemaKeepsEveryForeignKeyAndIndex(?string $url): void
    {
        $with    = $this->manager($url, true);
        $without = $this->manager($url, false);
        $classes = static fn (EntityManagerInterface $manager): array => $manager->getMetadataFactory()->getAllMetadata();

        self::assertSame(
            $this->shape((new SchemaTool($without))->getSchemaFromMetadata($classes($without))),
            $this->shape((new SchemaTool($with))->getSchemaFromMetadata($classes($with))),
        );
        $without->getConnection()->close();
    }

    #[DataProvider('databases')]
    public function testTheCreatedDatabaseCascadesAFormDeletionToItsSubmissions(?string $url): void
    {
        $manager = $this->manager($url, true);
        $tool    = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $connection    = $manager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        // @phpstan-ignore function.alreadyNarrowedType (introspectTableByUnquotedName() exists from DBAL 4.3; DBAL 3 has introspectTable())
        $table = method_exists($schemaManager, 'introspectTableByUnquotedName') ? $schemaManager->introspectTableByUnquotedName('nowo_contact_submission') : $schemaManager->introspectTable('nowo_contact_submission');
        self::assertContains('form_id->nowo_contact_form(id) CASCADE', $this->shape($this->schemaOf($table))['nowo_contact_submission']['fks']);
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame(
                ClientReferenceCollationListener::collationFor($connection->getDatabasePlatform()),
                $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_contact_submission' AND COLUMN_NAME = 'client_reference'"),
            );
        }

        $form = (new ContactForm())->setName('Support')->setSlug('support');
        $manager->persist($form);
        $submission = (new ContactSubmission())->setForm($form)->setLocale('en');
        $submission->addValue((new ContactSubmissionValue())->setFieldName('email')->setValue('a@example.com'));
        $manager->persist($submission);
        $manager->flush();

        $connection->executeStatement('DELETE FROM nowo_contact_form WHERE id = ?', [$form->getId()]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission_value'));
    }

    private function manager(?string $url, bool $listener): EntityManagerInterface
    {
        if ($url === null) {
            self::markTestSkipped('Set CF_MYSQL_URL, CF_MARIADB_URL and CF_POSTGRES_URL to run the real-database tests.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $eventManager = new EventManager();
        if ($listener) {
            $eventManager->addEventListener(ToolEvents::postGenerateSchema, new ClientReferenceCollationListener());
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        /** @phpstan-ignore argument.type (DsnParser output) */
        $manager = new EntityManager(DriverManager::getConnection($params, $config), $config, $eventManager);
        if ($listener) {
            $this->manager = $manager;
        }

        return $manager;
    }

    private function schemaOf(Table $table): Schema
    {
        return new Schema([$table]);
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and indexes (columns, uniqueness) per table.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    private function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = ($index->isPrimary() ? 'primary ' : ($index->isUnique() ? 'unique ' : '')) . implode(',', array_map(strtolower(...), $index->getColumns()));
            }
            // Introspected names come quoted ("form_id", `form_id`); generated ones do not.
            $fks     = str_replace(['"', '`'], '', $fks);
            $indexes = str_replace(['"', '`'], '', $indexes);
            sort($fks);
            sort($indexes);
            $shape[strtolower(str_replace(['"', '`'], '', $table->getName()))] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }
}
