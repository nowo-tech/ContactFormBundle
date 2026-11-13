<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Collation;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\ContactFormBundle\Collation\ContactFormExactKeyColumns;
use Nowo\ContactFormBundle\Doctrine\ClientReferenceCollationListener;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;

use function dirname;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 gate for this bundle's own CI, against a real MySQL / MariaDB server
 * (`NOWO_CONTACT_FORM_TEST_MYSQL_URL`; skipped without it).
 *
 * The schema is created from the bundle's own mapping with the real collation listener, so the run asserts what the
 * database actually ends up with rather than what the listener intended. A key column added to the mapping and left
 * on the server default fails here, in this bundle's pipeline, before any host installs it.
 */
final class ContactFormExactKeyColumnsTest extends ExactKeyColumnsTestCase
{
    private ?EntityManagerInterface $manager = null;

    protected function tearDown(): void
    {
        if ($this->manager instanceof EntityManagerInterface) {
            (new SchemaTool($this->manager))->dropSchema($this->metadata($this->manager));
            $this->manager->getConnection()->close();
            $this->manager = null;
        }

        parent::tearDown();
    }

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'NOWO_CONTACT_FORM_TEST_MYSQL_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new ContactFormExactKeyColumns()];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $manager = new EntityManager($connection, $config);
        $manager->getEventManager()->addEventListener(
            ToolEvents::postGenerateSchemaTable,
            new ClientReferenceCollationListener(null, $connection->getDatabasePlatform()),
        );
        $this->manager = $manager;

        $tool = new SchemaTool($manager);
        $tool->dropSchema($this->metadata($manager));
        $tool->createSchema($this->metadata($manager));
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function metadata(EntityManagerInterface $manager): array
    {
        return [$manager->getClassMetadata(ContactSubmission::class)];
    }
}
