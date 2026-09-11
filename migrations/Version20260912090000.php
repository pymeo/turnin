<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Swap: the first exchange loop.
 *
 * Two tables and, between them, four indexes — one per query this slice
 * actually runs. Nothing speculative: an index that no query uses is a write
 * cost with no reader.
 *
 * The frontier of visibility is `swap_pool_id`, never the workplace. Two people
 * in the same hospital but different pools must not see each other, and that is
 * why every discovery index starts with the pool.
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Swap requests and explicit availability, scoped by swap pool';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE swap_requests (
                id UUID NOT NULL,
                worker_id UUID NOT NULL,
                worker_assignment_id UUID NOT NULL,
                swap_pool_id UUID NOT NULL,
                roster_day_id UUID NOT NULL,
                work_date DATE NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_assignment_fk FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');

        // A second tap on a phone must not publish the same shift twice. The
        // partial index lets the same day be republished after a withdrawal,
        // which is a different request and keeps the cancelled one on record.
        $this->addSql("CREATE UNIQUE INDEX swap_requests_open_unique ON swap_requests (worker_assignment_id, work_date) WHERE status = 'open'");
        // "What does my group need covering from today on."
        $this->addSql('CREATE INDEX swap_requests_pool_date ON swap_requests (swap_pool_id, work_date, status)');
        // "My published shifts."
        $this->addSql('CREATE INDEX swap_requests_worker_status ON swap_requests (worker_id, status, work_date)');

        $this->addSql(<<<'SQL'
            CREATE TABLE swap_availabilities (
                id UUID NOT NULL,
                worker_id UUID NOT NULL,
                worker_assignment_id UUID NOT NULL,
                swap_pool_id UUID NOT NULL,
                work_date DATE NOT NULL,
                active BOOLEAN NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('ALTER TABLE swap_availabilities ADD CONSTRAINT swap_availabilities_assignment_fk FOREIGN KEY (worker_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_availabilities ADD CONSTRAINT swap_availabilities_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');

        // One statement per worker, pool and day — withdrawing flips `active`
        // rather than deleting, so declaring again reuses this row. The
        // constraint is what makes "Puedo hacerlo" idempotent in the database
        // and not only in the interface.
        $this->addSql('CREATE UNIQUE INDEX swap_availability_unique ON swap_availabilities (worker_id, swap_pool_id, work_date)');
        // "Who offered to work that day in that group."
        $this->addSql('CREATE INDEX swap_availability_pool_date ON swap_availabilities (swap_pool_id, work_date) WHERE active = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE swap_availabilities');
        $this->addSql('DROP TABLE swap_requests');
    }
}
