<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pool exchange policies, supervisor assignments and approval/redemption proposal data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workforce_shift_exchange_policies (swap_pool_id UUID NOT NULL, requires_approval BOOLEAN NOT NULL DEFAULT FALSE, allows_coverage BOOLEAN NOT NULL DEFAULT FALSE, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(swap_pool_id))');
        $this->addSql('ALTER TABLE workforce_shift_exchange_policies ADD CONSTRAINT workforce_exchange_policy_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE workforce_swap_supervisors (swap_pool_id UUID NOT NULL, supervisor_user_id UUID NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(swap_pool_id, supervisor_user_id))');
        $this->addSql('ALTER TABLE workforce_swap_supervisors ADD CONSTRAINT workforce_swap_supervisor_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_swap_supervisors ADD CONSTRAINT workforce_swap_supervisor_user_fk FOREIGN KEY (supervisor_user_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_proposals ADD exchange_balance_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE swap_proposals ADD reserved_minutes INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE swap_proposals ADD approved_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposal_balance_fk FOREIGN KEY (exchange_balance_id) REFERENCES swap_exchange_balances (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposal_approver_fk FOREIGN KEY (approved_by) REFERENCES identity_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposal_reserved_minutes_ck CHECK (reserved_minutes >= 0)');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind IN ('coverage', 'deferred') AND offered_roster_day_id IS NULL AND offered_work_date IS NULL AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'redemption' AND offered_roster_day_id IS NULL AND offered_work_date IS NULL AND exchange_balance_id IS NOT NULL AND reserved_minutes > 0))");
        $this->addSql('CREATE UNIQUE INDEX swap_balance_source_unique ON swap_exchange_balances (source_request_id)');
        $this->addSql("CREATE UNIQUE INDEX swap_proposal_balance_request_active_unique ON swap_proposals (exchange_balance_id, request_id) WHERE kind = 'redemption' AND status IN ('pending', 'pending_approval')");
        $this->addSql("CREATE UNIQUE INDEX swap_proposal_request_pending_approval_unique ON swap_proposals (request_id) WHERE status = 'pending_approval'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workforce_swap_supervisors');
        $this->addSql('DROP TABLE workforce_shift_exchange_policies');
        $this->addSql('DROP INDEX swap_balance_source_unique');
        $this->addSql('DROP INDEX swap_proposal_balance_request_active_unique');
        $this->addSql('DROP INDEX swap_proposal_request_pending_approval_unique');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposal_balance_fk');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposal_approver_fk');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposal_reserved_minutes_ck');
        $this->addSql('ALTER TABLE swap_proposals DROP exchange_balance_id, DROP reserved_minutes, DROP approved_by');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind IN ('coverage', 'deferred') AND offered_roster_day_id IS NULL AND offered_work_date IS NULL) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL))");
    }
}
