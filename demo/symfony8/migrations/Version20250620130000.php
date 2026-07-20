<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historical: select_options was originally added here; it is now created in Version20250619100000.
 * Kept as a no-op so existing demo DBs that already recorded this version stay consistent.
 */
final class Version20250620130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: select_options already exists on contact form field translations (initial schema).';
    }

    public function up(Schema $schema): void
    {
        // Column is created in Version20250619100000 for fresh installs.
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('SQLite demo migrations cannot be reverted safely.');
    }
}
