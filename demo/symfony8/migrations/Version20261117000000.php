<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bundle 1.7: the submission keeps the `client_reference` identity key (exact-match collation on MySQL / MariaDB).
 */
final class Version20261117000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nowo_contact_submission.client_reference (bundle 1.7).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_submission ADD client_reference VARCHAR(64) DEFAULT NULL COLLATE `utf8mb4_0900_bin`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_submission DROP client_reference');
    }
}
