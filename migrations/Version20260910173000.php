<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity users and authentication capabilities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_users (id UUID NOT NULL, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, has_worker_profile BOOLEAN NOT NULL DEFAULT FALSE, has_supervisor_profile BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX identity_users_email_unique ON identity_users (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_users');
    }
}
