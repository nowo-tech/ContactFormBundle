<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates Contact Form Bundle tables for the FrankenPHP demo (MySQL — REQ-DEMO-011).
 */
final class Version20250619100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact form bundle tables (forms, fields, submissions) for MySQL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE nowo_contact_form (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, privacy_policy_url VARCHAR(500) DEFAULT NULL, retention_days INT DEFAULT 365 NOT NULL, require_consent TINYINT(1) DEFAULT 1 NOT NULL, notification_email VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_contact_form_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE nowo_contact_form_translation (id INT AUTO_INCREMENT NOT NULL, form_id INT NOT NULL, locale VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, success_message LONGTEXT DEFAULT NULL, consent_label LONGTEXT DEFAULT NULL, privacy_policy_text LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_contact_form_translation_locale (form_id, locale), INDEX IDX_CONTACT_FORM_TRANSLATION_FORM (form_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE nowo_contact_form_translation ADD CONSTRAINT FK_CONTACT_FORM_TRANSLATION_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE nowo_contact_form_field (id INT AUTO_INCREMENT NOT NULL, form_id INT NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, required TINYINT(1) DEFAULT 0 NOT NULL, sort_order INT DEFAULT 0 NOT NULL, options JSON DEFAULT NULL, UNIQUE INDEX uniq_contact_form_field_name (form_id, name), INDEX IDX_CONTACT_FORM_FIELD_FORM (form_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE nowo_contact_form_field ADD CONSTRAINT FK_CONTACT_FORM_FIELD_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE nowo_contact_form_field_translation (id INT AUTO_INCREMENT NOT NULL, field_id INT NOT NULL, locale VARCHAR(10) NOT NULL, label VARCHAR(255) NOT NULL, placeholder VARCHAR(255) DEFAULT NULL, help LONGTEXT DEFAULT NULL, select_options JSON DEFAULT NULL, UNIQUE INDEX uniq_contact_form_field_translation_locale (field_id, locale), INDEX IDX_CONTACT_FORM_FIELD_TRANSLATION_FIELD (field_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE nowo_contact_form_field_translation ADD CONSTRAINT FK_CONTACT_FORM_FIELD_TRANSLATION_FIELD FOREIGN KEY (field_id) REFERENCES nowo_contact_form_field (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE nowo_contact_submission (id INT AUTO_INCREMENT NOT NULL, form_id INT NOT NULL, client_id INT DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, locale VARCHAR(10) NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, consent_given_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CONTACT_SUBMISSION_FORM (form_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE nowo_contact_submission ADD CONSTRAINT FK_CONTACT_SUBMISSION_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE nowo_contact_submission_value (id INT AUTO_INCREMENT NOT NULL, submission_id INT NOT NULL, field_name VARCHAR(120) NOT NULL, value LONGTEXT NOT NULL, INDEX IDX_CONTACT_SUBMISSION_VALUE_SUBMISSION (submission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE nowo_contact_submission_value ADD CONSTRAINT FK_CONTACT_SUBMISSION_VALUE_SUBMISSION FOREIGN KEY (submission_id) REFERENCES nowo_contact_submission (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nowo_contact_submission_value DROP FOREIGN KEY FK_CONTACT_SUBMISSION_VALUE_SUBMISSION');
        $this->addSql('ALTER TABLE nowo_contact_submission DROP FOREIGN KEY FK_CONTACT_SUBMISSION_FORM');
        $this->addSql('ALTER TABLE nowo_contact_form_field_translation DROP FOREIGN KEY FK_CONTACT_FORM_FIELD_TRANSLATION_FIELD');
        $this->addSql('ALTER TABLE nowo_contact_form_field DROP FOREIGN KEY FK_CONTACT_FORM_FIELD_FORM');
        $this->addSql('ALTER TABLE nowo_contact_form_translation DROP FOREIGN KEY FK_CONTACT_FORM_TRANSLATION_FORM');
        $this->addSql('DROP TABLE nowo_contact_submission_value');
        $this->addSql('DROP TABLE nowo_contact_submission');
        $this->addSql('DROP TABLE nowo_contact_form_field_translation');
        $this->addSql('DROP TABLE nowo_contact_form_field');
        $this->addSql('DROP TABLE nowo_contact_form_translation');
        $this->addSql('DROP TABLE nowo_contact_form');
    }
}
