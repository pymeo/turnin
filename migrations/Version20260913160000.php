<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A direct exchange offers one to five return shifts and the request owner
 * picks one, so the single `offered_*` pair on the proposal becomes a child
 * table plus a pointer to the chosen row.
 */
final class Version20260913160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Direct exchange proposals carry one to five return options';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE swap_proposal_options (id UUID NOT NULL, proposal_id UUID NOT NULL, assignment_id UUID NOT NULL, roster_day_id UUID NOT NULL, work_date DATE NOT NULL, position SMALLINT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE swap_proposal_options ADD CONSTRAINT swap_proposal_option_proposal_fk FOREIGN KEY (proposal_id) REFERENCES swap_proposals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_proposal_options ADD CONSTRAINT swap_proposal_option_assignment_fk FOREIGN KEY (assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
        // Offering the same day twice is one option, not two.
        $this->addSql('CREATE UNIQUE INDEX swap_proposal_option_unique ON swap_proposal_options (proposal_id, assignment_id, work_date)');
        $this->addSql('CREATE INDEX swap_proposal_option_proposal_idx ON swap_proposal_options (proposal_id)');
        $this->addSql('ALTER TABLE swap_proposal_options ADD CONSTRAINT swap_proposal_option_position_ck CHECK (position >= 0 AND position < 5)');

        // No foreign key back to the option: deleting a proposal cascades into
        // the very rows it would point at, and a proposal is never deleted.
        $this->addSql('ALTER TABLE swap_proposals ADD chosen_option_id UUID DEFAULT NULL');

        // Every exchange written before this migration had exactly one return
        // shift. gen_random_uuid() rather than a v7: these are historical rows
        // with no ordering to preserve, and application code never mints ids here.
        $this->addSql("INSERT INTO swap_proposal_options (id, proposal_id, assignment_id, roster_day_id, work_date, position) SELECT gen_random_uuid(), id, proposer_assignment_id, offered_roster_day_id, offered_work_date, 0 FROM swap_proposals WHERE kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL");
        $this->addSql("UPDATE swap_proposals p SET chosen_option_id = o.id FROM swap_proposal_options o WHERE o.proposal_id = p.id AND p.status IN ('executed', 'accepted', 'pending_approval')");

        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql('ALTER TABLE swap_proposals DROP offered_roster_day_id');
        $this->addSql('ALTER TABLE swap_proposals DROP offered_work_date');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind IN ('coverage', 'deferred') AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'exchange' AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'redemption' AND exchange_balance_id IS NOT NULL AND reserved_minutes > 0))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_proposals ADD offered_roster_day_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE swap_proposals ADD offered_work_date DATE DEFAULT NULL');
        $this->addSql('UPDATE swap_proposals p SET offered_roster_day_id = o.roster_day_id, offered_work_date = o.work_date FROM swap_proposal_options o WHERE o.proposal_id = p.id AND o.position = 0');
        $this->addSql('ALTER TABLE swap_proposals DROP chosen_option_id');
        $this->addSql('DROP TABLE swap_proposal_options');
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind IN ('coverage', 'deferred') AND offered_roster_day_id IS NULL AND offered_work_date IS NULL AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL AND exchange_balance_id IS NULL AND reserved_minutes = 0) OR (kind = 'redemption' AND offered_roster_day_id IS NULL AND offered_work_date IS NULL AND exchange_balance_id IS NOT NULL AND reserved_minutes > 0))");
    }
}
