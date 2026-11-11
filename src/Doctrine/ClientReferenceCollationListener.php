<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
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
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final readonly class ClientReferenceCollationListener
{
    public const FIELD = 'clientReference';

    public function __construct(
        private ?ContactFormEntityManagerResolver $entityManagerResolver = null,
        private ?AbstractPlatform $platform = null,
    ) {
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

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== ContactSubmission::class) {
            return;
        }

        $platform  = $this->platform ?? $this->entityManagerResolver?->get()->getConnection()->getDatabasePlatform();
        $collation = $platform instanceof AbstractPlatform ? self::collationFor($platform) : null;
        if ($collation === null) {
            return;
        }

        $column = $metadata->getColumnName(self::FIELD);
        // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5; ORM 3 allows DBAL 3.8)
        $edited = self::collate($args->getClassTable(), $column, $collation, method_exists(Table::class, 'edit'));
        if ($edited instanceof Table) {
            $args->setClassTable($edited);
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
