<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Concurrency;

/**
 * An entity whose admin edits are checked against a row version (REQ-RUNTIME-003 rule 4).
 *
 * The version is a plain integer column (`row_version`), starting at 1, that every admin save advances by one in the same transaction
 * as the save ({@see RowVersionGuard}). The edit form carries the version of the page as a hidden field.
 */
interface VersionedRowInterface
{
    /**
     * The version of the row as last read or written by this process.
     */
    public function getRowVersion(): int;

    /**
     * Records the version the row now has in the database (set by {@see RowVersionGuard} after it advanced the row).
     */
    public function setRowVersion(int $version): static;
}
