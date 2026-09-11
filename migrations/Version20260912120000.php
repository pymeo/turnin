<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Availability becomes one idempotent slot per semantic shift kind. */
final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ShiftKind to swap requests and availability slots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_requests ADD shift_kind VARCHAR(32)');
        $this->addSql(<<<'SQL'
            UPDATE swap_requests r
               SET shift_kind = COALESCE(
                   (SELECT s.kind FROM scheduling_roster_segments s WHERE s.roster_day_id = r.roster_day_id ORDER BY s.position LIMIT 1),
                   'other'
               )
            SQL);
        $this->addSql('ALTER TABLE swap_requests ALTER shift_kind SET NOT NULL');

        $this->addSql('ALTER TABLE swap_availabilities ADD shift_kind VARCHAR(32)');
        $this->addSql("UPDATE swap_availabilities SET shift_kind = 'morning'");
        $this->addSql('DROP INDEX swap_availability_unique');
        // A legacy date-only declaration meant any ordinary shift. Preserve
        // that meaning by materialising the other two concrete kinds.
        $this->addSql(<<<'SQL'
            INSERT INTO swap_availabilities
                (id, worker_id, worker_assignment_id, swap_pool_id, work_date, shift_kind, active, created_at, updated_at)
            SELECT uuidv7(), worker_id, worker_assignment_id, swap_pool_id, work_date, 'evening', active, created_at, updated_at
              FROM swap_availabilities
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO swap_availabilities
                (id, worker_id, worker_assignment_id, swap_pool_id, work_date, shift_kind, active, created_at, updated_at)
            SELECT uuidv7(), worker_id, worker_assignment_id, swap_pool_id, work_date, 'night', active, created_at, updated_at
              FROM swap_availabilities
             WHERE shift_kind = 'morning'
            SQL);
        $this->addSql('ALTER TABLE swap_availabilities ALTER shift_kind SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX swap_availability_slot_unique ON swap_availabilities (worker_id, swap_pool_id, work_date, shift_kind)');
        $this->addSql('DROP INDEX swap_availability_pool_date');
        $this->addSql('CREATE INDEX swap_availability_pool_date_kind ON swap_availabilities (swap_pool_id, work_date, shift_kind) WHERE active = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX swap_availability_slot_unique');
        $this->addSql('DROP INDEX swap_availability_pool_date_kind');
        $this->addSql("DELETE FROM swap_availabilities WHERE shift_kind <> 'morning'");
        $this->addSql('ALTER TABLE swap_availabilities DROP shift_kind');
        $this->addSql('CREATE UNIQUE INDEX swap_availability_unique ON swap_availabilities (worker_id, swap_pool_id, work_date)');
        $this->addSql('CREATE INDEX swap_availability_pool_date ON swap_availabilities (swap_pool_id, work_date) WHERE active = TRUE');
        $this->addSql('ALTER TABLE swap_requests DROP shift_kind');
    }
}
