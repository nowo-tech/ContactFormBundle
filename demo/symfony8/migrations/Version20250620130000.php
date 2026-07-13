<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds translatable select option labels to field translations.
 */
final class Version20250620130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add select_options column to contact form field translations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_form_field_translation ADD COLUMN select_options CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('SQLite demo migrations cannot be reverted safely.');
    }
}
