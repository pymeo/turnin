<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar connection capabilities/account context and personal calendar blocks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduling_external_calendar_connections ADD account_email VARCHAR(320) DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduling_external_calendar_connections ADD reauthentication_required_at TIMESTAMPTZ DEFAULT NULL');

        $this->addSql('CREATE TABLE scheduling_calendar_blocks (id UUID NOT NULL, worker_id UUID NOT NULL, title VARCHAR(160) NOT NULL, type VARCHAR(32) NOT NULL, starts_at TIMESTAMPTZ NOT NULL, ends_at TIMESTAMPTZ NOT NULL, all_day BOOLEAN NOT NULL, blocks_availability BOOLEAN NOT NULL, source VARCHAR(32) NOT NULL, external_calendar_id VARCHAR(1024) DEFAULT NULL, external_event_id VARCHAR(1024) DEFAULT NULL, external_updated_at TIMESTAMPTZ DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX scheduling_calendar_blocks_range_idx ON scheduling_calendar_blocks (worker_id, starts_at, ends_at)');
        $this->addSql("CREATE UNIQUE INDEX scheduling_calendar_blocks_external_unique ON scheduling_calendar_blocks (worker_id, external_calendar_id, external_event_id) WHERE source = 'google_calendar'");
        $this->addSql('ALTER TABLE scheduling_calendar_blocks ADD CONSTRAINT scheduling_calendar_blocks_worker_fk FOREIGN KEY (worker_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduling_calendar_blocks ADD CONSTRAINT scheduling_calendar_blocks_interval_ck CHECK (ends_at > starts_at)');
        $this->addSql("ALTER TABLE scheduling_calendar_blocks ADD CONSTRAINT scheduling_calendar_blocks_external_ck CHECK ((source = 'google_calendar' AND external_calendar_id IS NOT NULL AND external_event_id IS NOT NULL) OR (source <> 'google_calendar' AND external_calendar_id IS NULL AND external_event_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduling_calendar_blocks');
        $this->addSql('ALTER TABLE scheduling_external_calendar_connections DROP reauthentication_required_at');
        $this->addSql('ALTER TABLE scheduling_external_calendar_connections DROP account_email');
    }
}
