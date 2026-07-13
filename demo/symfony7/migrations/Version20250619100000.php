<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates Contact Form Bundle tables for the demo (SQLite).
 */
final class Version20250619100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact form bundle tables (forms, fields, submissions).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE nowo_contact_form (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, enabled BOOLEAN DEFAULT 1 NOT NULL, privacy_policy_url VARCHAR(500) DEFAULT NULL, retention_days INTEGER DEFAULT 365 NOT NULL, require_consent BOOLEAN DEFAULT 1 NOT NULL, notification_email VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_form_slug ON nowo_contact_form (slug)');

        $this->addSql('CREATE TABLE nowo_contact_form_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, form_id INTEGER NOT NULL, locale VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, success_message CLOB DEFAULT NULL, consent_label CLOB DEFAULT NULL, privacy_policy_text CLOB DEFAULT NULL, CONSTRAINT FK_CONTACT_FORM_TRANSLATION_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_form_translation_locale ON nowo_contact_form_translation (form_id, locale)');

        $this->addSql('CREATE TABLE nowo_contact_form_field (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, form_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, required BOOLEAN DEFAULT 0 NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, options CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_CONTACT_FORM_FIELD_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_form_field_name ON nowo_contact_form_field (form_id, name)');

        $this->addSql('CREATE TABLE nowo_contact_form_field_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, field_id INTEGER NOT NULL, locale VARCHAR(10) NOT NULL, label VARCHAR(255) NOT NULL, placeholder VARCHAR(255) DEFAULT NULL, help CLOB DEFAULT NULL, select_options CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_CONTACT_FORM_FIELD_TRANSLATION_FIELD FOREIGN KEY (field_id) REFERENCES nowo_contact_form_field (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_form_field_translation_locale ON nowo_contact_form_field_translation (field_id, locale)');

        $this->addSql('CREATE TABLE nowo_contact_submission (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, form_id INTEGER NOT NULL, client_id INTEGER DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, locale VARCHAR(10) NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, consent_given_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_CONTACT_SUBMISSION_FORM FOREIGN KEY (form_id) REFERENCES nowo_contact_form (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');

        $this->addSql('CREATE TABLE nowo_contact_submission_value (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, submission_id INTEGER NOT NULL, field_name VARCHAR(120) NOT NULL, value CLOB NOT NULL, CONSTRAINT FK_CONTACT_SUBMISSION_VALUE_SUBMISSION FOREIGN KEY (submission_id) REFERENCES nowo_contact_submission (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE nowo_contact_submission_value');
        $this->addSql('DROP TABLE nowo_contact_submission');
        $this->addSql('DROP TABLE nowo_contact_form_field_translation');
        $this->addSql('DROP TABLE nowo_contact_form_field');
        $this->addSql('DROP TABLE nowo_contact_form_translation');
        $this->addSql('DROP TABLE nowo_contact_form');
    }
}
