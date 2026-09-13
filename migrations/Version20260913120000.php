<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Real exchange and coverage proposals referencing roster shifts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE swap_proposals (id UUID NOT NULL, request_id UUID NOT NULL, request_owner_id UUID NOT NULL, proposer_id UUID NOT NULL, proposer_assignment_id UUID NOT NULL, kind VARCHAR(16) NOT NULL, offered_roster_day_id UUID DEFAULT NULL, offered_work_date DATE DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_request_fk FOREIGN KEY (request_id) REFERENCES swap_requests (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_proposer_assignment_fk FOREIGN KEY (proposer_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind = 'coverage' AND offered_roster_day_id IS NULL AND offered_work_date IS NULL) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL))");
        $this->addSql("CREATE UNIQUE INDEX swap_proposals_pending_unique ON swap_proposals (request_id, proposer_id, kind, COALESCE(offered_roster_day_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE status = 'pending'");
        $this->addSql('CREATE INDEX swap_proposals_owner_status ON swap_proposals (request_owner_id, status, created_at)');
        $this->addSql('CREATE INDEX swap_proposals_proposer_status ON swap_proposals (proposer_id, status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE swap_proposals');
    }
}
