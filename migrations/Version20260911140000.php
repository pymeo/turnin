<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Scheduling: the worker's own roster.
 *
 * Two things in this schema are load-bearing. There is no "unknown" state — a
 * day the worker has not told us about simply has no row — and the times are
 * TIME, not TIMESTAMPTZ: a shift labelled 22:00 starts at 22:00 on the wall
 * clock, in March and in October alike.
 */
final class Version20260911140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal shift calendar: presets, roster days with segments, and repeating patterns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduling_shift_presets (
                id UUID NOT NULL,
                worker_assignment_id UUID NOT NULL,
                name VARCHAR(40) NOT NULL,
                abbreviation VARCHAR(3) NOT NULL,
                starts_at TIME(0) WITHOUT TIME ZONE NOT NULL,
                ends_at TIME(0) WITHOUT TIME ZONE NOT NULL,
                kind VARCHAR(32) NOT NULL,
                aliases JSON NOT NULL,
                position SMALLINT NOT NULL,
                active BOOLEAN NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE scheduling_shift_presets ADD CONSTRAINT scheduling_presets_assignment_fk FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX scheduling_presets_assignment_active ON scheduling_shift_presets (worker_assignment_id, active)');

        $this->addSql(<<<'SQL'
            CREATE TABLE scheduling_roster_days (
                id UUID NOT NULL,
                worker_assignment_id UUID NOT NULL,
                work_date DATE NOT NULL,
                state VARCHAR(16) NOT NULL,
                source VARCHAR(16) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE scheduling_roster_days ADD CONSTRAINT scheduling_roster_days_assignment_fk FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        // The invariant the application must never be the only guard for: one
        // worker, one day, one answer.
        $this->addSql('CREATE UNIQUE INDEX scheduling_roster_day_unique ON scheduling_roster_days (worker_assignment_id, work_date)');

        $this->addSql(<<<'SQL'
            CREATE TABLE scheduling_roster_segments (
                id UUID NOT NULL,
                roster_day_id UUID NOT NULL,
                shift_preset_id UUID DEFAULT NULL,
                label_snapshot VARCHAR(40) NOT NULL,
                abbreviation_snapshot VARCHAR(3) NOT NULL,
                starts_at TIME(0) WITHOUT TIME ZONE NOT NULL,
                ends_at TIME(0) WITHOUT TIME ZONE NOT NULL,
                kind VARCHAR(32) NOT NULL,
                position SMALLINT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE scheduling_roster_segments ADD CONSTRAINT scheduling_segments_day_fk FOREIGN KEY (roster_day_id) REFERENCES scheduling_roster_days (id) ON DELETE CASCADE');
        // A preset can be retired but never deleted, so this only ever fires if
        // an assignment goes: the snapshot on the segment keeps it readable.
        $this->addSql('ALTER TABLE scheduling_roster_segments ADD CONSTRAINT scheduling_segments_preset_fk FOREIGN KEY (shift_preset_id) REFERENCES scheduling_shift_presets (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX scheduling_segments_day ON scheduling_roster_segments (roster_day_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE scheduling_roster_patterns (
                id UUID NOT NULL,
                worker_assignment_id UUID NOT NULL,
                name VARCHAR(60) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE scheduling_roster_patterns ADD CONSTRAINT scheduling_patterns_assignment_fk FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX scheduling_patterns_assignment ON scheduling_roster_patterns (worker_assignment_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE scheduling_roster_pattern_slots (
                pattern_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                slot_type VARCHAR(16) NOT NULL,
                shift_preset_id UUID DEFAULT NULL,
                PRIMARY KEY(pattern_id, position)
            )
            SQL);
        $this->addSql('ALTER TABLE scheduling_roster_pattern_slots ADD CONSTRAINT scheduling_pattern_slots_pattern_fk FOREIGN KEY (pattern_id) REFERENCES scheduling_roster_patterns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduling_roster_pattern_slots ADD CONSTRAINT scheduling_pattern_slots_preset_fk FOREIGN KEY (shift_preset_id) REFERENCES scheduling_shift_presets (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduling_roster_pattern_slots');
        $this->addSql('DROP TABLE scheduling_roster_patterns');
        $this->addSql('DROP TABLE scheduling_roster_segments');
        $this->addSql('DROP TABLE scheduling_roster_days');
        $this->addSql('DROP TABLE scheduling_shift_presets');
    }
}
