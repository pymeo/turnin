<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team-verified supervisors: invitations, per-pool assignments with status history, and unique colleague verifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workforce_supervisor_invitations (id UUID NOT NULL, swap_pool_id UUID NOT NULL, invited_by_worker_id UUID NOT NULL, token_hash CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, expires_at TIMESTAMPTZ NOT NULL, responded_by_user_id UUID DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, responded_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE workforce_supervisor_invitations ADD CONSTRAINT workforce_supervisor_invitation_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_supervisor_invitations ADD CONSTRAINT workforce_supervisor_invitation_inviter_fk FOREIGN KEY (invited_by_worker_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_supervisor_invitations ADD CONSTRAINT workforce_supervisor_invitation_responder_fk FOREIGN KEY (responded_by_user_id) REFERENCES identity_users (id) ON DELETE SET NULL');
        $this->addSql("ALTER TABLE workforce_supervisor_invitations ADD CONSTRAINT workforce_supervisor_invitation_status_ck CHECK (status IN ('pending', 'accepted', 'declined', 'superseded'))");
        $this->addSql('ALTER TABLE workforce_supervisor_invitations ADD CONSTRAINT workforce_supervisor_invitation_expiry_ck CHECK (expires_at > created_at)');
        $this->addSql('CREATE UNIQUE INDEX workforce_supervisor_invitation_token_unique ON workforce_supervisor_invitations (token_hash)');
        $this->addSql("CREATE INDEX workforce_supervisor_invitation_pending_idx ON workforce_supervisor_invitations (invited_by_worker_id, swap_pool_id) WHERE status = 'pending'");

        $this->addSql('CREATE TABLE workforce_supervisor_assignments (id UUID NOT NULL, supervisor_user_id UUID NOT NULL, swap_pool_id UUID NOT NULL, invitation_id UUID DEFAULT NULL, verification_token_hash CHAR(64) NOT NULL, status VARCHAR(24) NOT NULL, verification_level VARCHAR(32) DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, verified_at TIMESTAMPTZ DEFAULT NULL, left_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        // Leaving never deletes a row (status LEFT keeps the history); only
        // erasing the account or the pool itself does, like everywhere else.
        $this->addSql('ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_user_fk FOREIGN KEY (supervisor_user_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_invitation_fk FOREIGN KEY (invitation_id) REFERENCES workforce_supervisor_invitations (id) ON DELETE SET NULL');
        $this->addSql("ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_status_ck CHECK (status IN ('pending_verification', 'verified', 'left', 'revoked'))");
        $this->addSql("ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_level_ck CHECK (verification_level IS NULL OR verification_level IN ('team_verified', 'organization_verified'))");
        $this->addSql("ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_verified_ck CHECK (status <> 'verified' OR (verified_at IS NOT NULL AND verification_level IS NOT NULL))");
        $this->addSql("ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_left_ck CHECK (status <> 'left' OR left_at IS NOT NULL)");
        $this->addSql('CREATE UNIQUE INDEX workforce_supervisor_assignment_token_unique ON workforce_supervisor_assignments (verification_token_hash)');
        $this->addSql("CREATE UNIQUE INDEX workforce_supervisor_assignment_active_unique ON workforce_supervisor_assignments (swap_pool_id, supervisor_user_id) WHERE status IN ('pending_verification', 'verified')");
        $this->addSql("CREATE INDEX workforce_supervisor_assignment_verified_idx ON workforce_supervisor_assignments (supervisor_user_id) WHERE status = 'verified'");

        $this->addSql('CREATE TABLE workforce_supervisor_verifications (id UUID NOT NULL, supervisor_assignment_id UUID NOT NULL, verifier_worker_id UUID NOT NULL, decision VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE workforce_supervisor_verifications ADD CONSTRAINT workforce_supervisor_verification_assignment_fk FOREIGN KEY (supervisor_assignment_id) REFERENCES workforce_supervisor_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_supervisor_verifications ADD CONSTRAINT workforce_supervisor_verification_verifier_fk FOREIGN KEY (verifier_worker_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE workforce_supervisor_verifications ADD CONSTRAINT workforce_supervisor_verification_decision_ck CHECK (decision IN ('confirmed', 'cannot_confirm'))");
        $this->addSql("ALTER TABLE workforce_supervisor_verifications ADD CONSTRAINT workforce_supervisor_verification_source_ck CHECK (source IN ('invitation', 'team_member') AND (source <> 'invitation' OR decision = 'confirmed'))");
        $this->addSql('CREATE UNIQUE INDEX workforce_supervisor_verification_unique ON workforce_supervisor_verifications (supervisor_assignment_id, verifier_worker_id)');

        // The old table was only ever filled by hand (there was no screen for
        // it), so its rows are administrative assignments. They keep their
        // authority as ORGANIZATION_VERIFIED; their verification link is a
        // random hash nobody holds, because they never need one.
        $this->addSql("INSERT INTO workforce_supervisor_assignments (id, supervisor_user_id, swap_pool_id, invitation_id, verification_token_hash, status, verification_level, created_at, verified_at, left_at) SELECT uuidv7(), supervisor_user_id, swap_pool_id, NULL, encode(sha256(convert_to(gen_random_uuid()::text || swap_pool_id::text || supervisor_user_id::text, 'UTF8')), 'hex'), CASE WHEN active THEN 'verified' ELSE 'left' END, 'organization_verified', created_at, created_at, CASE WHEN active THEN NULL ELSE created_at END FROM workforce_swap_supervisors");
        $this->addSql('DROP TABLE workforce_swap_supervisors');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workforce_swap_supervisors (swap_pool_id UUID NOT NULL, supervisor_user_id UUID NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(swap_pool_id, supervisor_user_id))');
        $this->addSql('ALTER TABLE workforce_swap_supervisors ADD CONSTRAINT workforce_swap_supervisor_pool_fk FOREIGN KEY (swap_pool_id) REFERENCES workforce_swap_pools (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workforce_swap_supervisors ADD CONSTRAINT workforce_swap_supervisor_user_fk FOREIGN KEY (supervisor_user_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO workforce_swap_supervisors (swap_pool_id, supervisor_user_id, active, created_at) SELECT DISTINCT ON (swap_pool_id, supervisor_user_id) swap_pool_id, supervisor_user_id, status = 'verified', created_at FROM workforce_supervisor_assignments WHERE status IN ('verified', 'left') ORDER BY swap_pool_id, supervisor_user_id, (status = 'verified') DESC, created_at DESC");
        $this->addSql('DROP TABLE workforce_supervisor_verifications');
        $this->addSql('DROP TABLE workforce_supervisor_assignments');
        $this->addSql('DROP TABLE workforce_supervisor_invitations');
    }
}
