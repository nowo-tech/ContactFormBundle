<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;

use function method_exists;

/**
 * Exact-match collation for `client_reference` on MySQL / MariaDB (1.7, audit LI-CF-L2).
 *
 * The default MySQL collations are case-, accent- and (with PAD SPACE) trailing-space-insensitive, so a host export or
 * erasure `WHERE client_reference = ?` would match other clients' ids (`tenantA` / `TENANTA` / `tenanta `, `tenant` /
 * `téñant`). The column gets a binary, no-pad collation where the server has one:
 *
 * - MySQL 8.0+: `utf8mb4_0900_bin`;
 * - MariaDB: `utf8mb4_nopad_bin`;
 * - MySQL 5.7: `utf8mb4_bin` (binary, but PAD SPACE: trailing spaces still compare equal).
 *
 * Other platforms (PostgreSQL, SQLite) already compare exactly and are left untouched; the collation cannot be put
 * in the mapping itself because they reject MySQL collation names. Applied to the generated schema (schema tool,
 * `doctrine:migrations:diff`), so migrations carry it.
 *
 * 1.13 (audit XH-1): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * 1.7.0–1.12.1 replaced the table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+), and the ORM schema tool
 * then added the submission's foreign keys to the table it had replaced: on MySQL / MariaDB the generated schema lost
 * `nowo_contact_submission.form_id → nowo_contact_form (ON DELETE CASCADE)` and its index. Now:
 *
 * - DBAL 4.5+: the table is rebuilt with its table editor (foreign keys and indexes included) and swapped into a new
 *   schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - older DBAL: the column's platform options are set in place (no editor exists there).
 *
 * 2.0 removed the no-op `postGenerateSchemaTable()` (deprecated since 1.13).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class ClientReferenceCollationListener
{
    public const FIELD = 'clientReference';

    /**
     * @param ContactFormEntityManagerResolver|null $entityManagerResolver unused since 1.13 (the platform comes from the
     *                                                                     schema tool's entity manager); kept for BC
     * @param AbstractPlatform|null $platform a fixed platform (tests); null: the platform of the schema tool's connection
     */
    public function __construct(
        ?ContactFormEntityManagerResolver $entityManagerResolver = null,
        private ?AbstractPlatform $platform = null,
    ) {
        unset($entityManagerResolver);
    }

    /**
     * The exact-match collation of `client_reference` on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    /**
     * Gives `client_reference` its exact-match collation in the generated schema, keeping every foreign key and index of
     * the table.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        if ($entityManager->getMetadataFactory()->isTransient(ContactSubmission::class)) {
            return;
        }

        $platform  = $this->platform ?? $entityManager->getConnection()->getDatabasePlatform();
        $collation = self::collationFor($platform);
        $metadata  = $entityManager->getClassMetadata(ContactSubmission::class);
        $schema    = $args->getSchema();
        $tableName = $metadata->getTableName();
        if ($collation === null || $tableName === '' || !$schema->hasTable($tableName)) {
            return;
        }

        // Table::edit() and Schema::edit() exist only from DBAL 4.5; ORM 3 allows DBAL 3.8.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
        $editor = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit');
        $edited = self::collate($schema->getTable($tableName), $metadata->getColumnName(self::FIELD), $collation, $editor);
        if ($edited instanceof Table) {
            $args->setSchema($schema->edit()->dropTableByUnquotedName($tableName)->addTable($edited)->create());
        }
    }

    /**
     * Gives `$column` of `$table` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the
     * new table) or in place (older DBAL; returns null).
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($column === '' || $collation === '') {
            return null;
        }

        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
