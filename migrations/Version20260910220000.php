<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Google external identities and password-optional users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_users ALTER COLUMN password_hash DROP NOT NULL');
        $this->addSql('CREATE TABLE identity_external_identities (id UUID NOT NULL, user_id UUID NOT NULL, provider VARCHAR(32) NOT NULL, provider_subject VARCHAR(255) NOT NULL, email_at_link_time VARCHAR(254) NOT NULL, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE identity_external_identities ADD CONSTRAINT identity_external_identities_user_fk FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX identity_external_provider_subject_unique ON identity_external_identities (provider, provider_subject)');
        $this->addSql('CREATE UNIQUE INDEX identity_external_user_provider_unique ON identity_external_identities (user_id, provider)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_external_identities');
        $this->addSql("UPDATE identity_users SET password_hash = '' WHERE password_hash IS NULL");
        $this->addSql('ALTER TABLE identity_users ALTER COLUMN password_hash SET NOT NULL');
    }
}
