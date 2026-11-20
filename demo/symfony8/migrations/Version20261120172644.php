<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bundle 1.12: the admin edits of forms and fields carry a row version (REQ-RUNTIME-003 rule 4). Generated with
 * doctrine:migrations:diff and limited to the two columns the bundle's UPGRADING "To 1.12.0" lists.
 */
final class Version20261120172644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nowo_contact_form.row_version and nowo_contact_form_field.row_version (bundle 1.12).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_form ADD row_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE nowo_contact_form_field ADD row_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_form DROP row_version');
        $this->addSql('ALTER TABLE nowo_contact_form_field DROP row_version');
    }
}
