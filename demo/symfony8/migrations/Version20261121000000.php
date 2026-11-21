<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bundle 2.0: the INTEGER client_id column is removed (deprecated since 1.7). Its value is first copied into
 * client_reference (the backfill of the bundle's UPGRADING "To 2.0.0"), then the column is dropped.
 */
final class Version20261121000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill nowo_contact_submission.client_reference from client_id, then drop client_id (bundle 2.0).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE nowo_contact_submission SET client_reference = CAST(client_id AS CHAR) WHERE client_id IS NOT NULL AND client_reference IS NULL');
        $this->addSql('ALTER TABLE nowo_contact_submission DROP client_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_submission ADD client_id INT DEFAULT NULL');
    }
}
