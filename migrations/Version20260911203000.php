<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypted external calendar connections, assignment mappings and idempotent event links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduling_external_calendar_connections (id VARCHAR(255) NOT NULL, user_id UUID NOT NULL, provider VARCHAR(32) NOT NULL, account_subject VARCHAR(255) NOT NULL, encrypted_access_token TEXT NOT NULL, encrypted_refresh_token TEXT DEFAULT NULL, expires_at TIMESTAMPTZ DEFAULT NULL, granted_scopes JSON NOT NULL, connected_at TIMESTAMPTZ NOT NULL, revoked_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_external_calendar_provider_user ON scheduling_external_calendar_connections (provider, user_id)');
        $this->addSql('ALTER TABLE scheduling_external_calendar_connections ADD CONSTRAINT fk_external_calendar_user FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE scheduling_external_calendar_mappings (connection_id VARCHAR(255) NOT NULL, external_calendar_id VARCHAR(1024) NOT NULL, worker_assignment_id UUID NOT NULL, direction VARCHAR(16) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, sync_token TEXT DEFAULT NULL, PRIMARY KEY(connection_id, external_calendar_id, worker_assignment_id, direction))');
        $this->addSql('CREATE INDEX idx_external_calendar_mapping_assignment ON scheduling_external_calendar_mappings (worker_assignment_id, active)');
        $this->addSql('ALTER TABLE scheduling_external_calendar_mappings ADD CONSTRAINT fk_external_mapping_connection FOREIGN KEY (connection_id) REFERENCES scheduling_external_calendar_connections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduling_external_calendar_mappings ADD CONSTRAINT fk_external_mapping_assignment FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE scheduling_external_roster_events (connection_id VARCHAR(255) NOT NULL, external_calendar_id VARCHAR(1024) NOT NULL, external_event_id VARCHAR(1024) NOT NULL, worker_assignment_id UUID NOT NULL, roster_day_id UUID NOT NULL, segment_id UUID DEFAULT NULL, direction VARCHAR(16) NOT NULL, external_updated_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(connection_id, external_calendar_id, external_event_id, worker_assignment_id, direction))');
        $this->addSql('CREATE INDEX idx_external_roster_segment ON scheduling_external_roster_events (connection_id, external_calendar_id, segment_id, direction)');
        $this->addSql('ALTER TABLE scheduling_external_roster_events ADD CONSTRAINT fk_external_roster_connection FOREIGN KEY (connection_id) REFERENCES scheduling_external_calendar_connections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduling_external_roster_events ADD CONSTRAINT fk_external_roster_assignment FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE scheduling_external_roster_events ADD CONSTRAINT fk_external_roster_day FOREIGN KEY (roster_day_id) REFERENCES scheduling_roster_days (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduling_external_roster_events ADD CONSTRAINT fk_external_roster_segment FOREIGN KEY (segment_id) REFERENCES scheduling_roster_segments (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduling_external_roster_events');
        $this->addSql('DROP TABLE scheduling_external_calendar_mappings');
        $this->addSql('DROP TABLE scheduling_external_calendar_connections');
    }
}
