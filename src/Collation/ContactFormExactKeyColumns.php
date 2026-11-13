<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Collation;

use Nowo\ContactFormBundle\Doctrine\ClientReferenceCollationListener;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\MigrationsKitBundle\Collation\ExactKeyColumn;
use Nowo\MigrationsKitBundle\Collation\ExactKeyColumnsInterface;

/**
 * Declares `client_reference` of the submission table as an identity key (REQ-SEC-006).
 *
 * `client_reference` is the host's own reference for the client a submission belongs to (set through the client
 * resolver, read back with `=` when submissions are listed or exported for that client). Under the MySQL 8+ server
 * default a column with no `COLLATE` inherits — `utf8mb4_0900_ai_ci`, case- and accent-insensitive and PAD SPACE —
 * `acme`, `ACME`, `acmé` and `acme ` would be the same client, so one tenant's submissions would appear in another's
 * list. {@see ClientReferenceCollationListener} gives it a binary NO PAD collation
 * when the schema is generated; this declaration is what checks the database that resulted.
 *
 * `ip_hash` is deliberately **not** declared: it is written and exported, never used as a lookup key.
 *
 * The table name carries `nowo_contact_form.doctrine.table_prefix`, so it is the table the host really has.
 *
 * Registered only when MigrationsKitBundle is installed (optional dependency).
 */
final readonly class ContactFormExactKeyColumns implements ExactKeyColumnsInterface
{
    public const OWNER = 'nowo-tech/contact-form-bundle';

    /** Table of {@see ContactSubmission}, before the configured prefix. */
    public const TABLE = 'nowo_contact_submission';

    public const COLUMN = 'client_reference';

    public function __construct(private string $tablePrefix = '')
    {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        return [
            new ExactKeyColumn(
                $this->tablePrefix . self::TABLE,
                self::COLUMN,
                self::OWNER,
                'host client reference: selects the submissions of one client, compared with =',
            ),
        ];
    }
}
