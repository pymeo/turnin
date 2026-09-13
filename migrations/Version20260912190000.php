<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds single-use schedule invitations and symmetric privacy-preserving schedule links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE coordination_schedule_invitations (
                id UUID NOT NULL,
                inviter_id UUID NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                status VARCHAR(16) NOT NULL,
                accepted_by UUID DEFAULT NULL,
                accepted_at TIMESTAMPTZ DEFAULT NULL,
                revoked_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_coordination_inviter FOREIGN KEY (inviter_id) REFERENCES identity_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_coordination_guest FOREIGN KEY (accepted_by) REFERENCES identity_users (id) ON DELETE CASCADE,
                CONSTRAINT chk_coordination_invitation_status CHECK (status IN ('pending', 'accepted', 'revoked')),
                CONSTRAINT chk_coordination_invitation_acceptance CHECK ((status = 'accepted') = (accepted_by IS NOT NULL AND accepted_at IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_coordination_invitation_token ON coordination_schedule_invitations (token_hash)');
        $this->addSql('CREATE INDEX idx_coordination_invitation_owner ON coordination_schedule_invitations (inviter_id, status)');
        $this->addSql(<<<'SQL'
            CREATE TABLE coordination_schedule_links (
                id UUID NOT NULL,
                first_user_id UUID NOT NULL,
                second_user_id UUID NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                revoked_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_coordination_first_user FOREIGN KEY (first_user_id) REFERENCES identity_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_coordination_second_user FOREIGN KEY (second_user_id) REFERENCES identity_users (id) ON DELETE CASCADE,
                CONSTRAINT chk_coordination_distinct_users CHECK (first_user_id < second_user_id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_coordination_active_pair ON coordination_schedule_links (first_user_id, second_user_id) WHERE revoked_at IS NULL');
        $this->addSql('CREATE INDEX idx_coordination_link_first ON coordination_schedule_links (first_user_id) WHERE revoked_at IS NULL');
        $this->addSql('CREATE INDEX idx_coordination_link_second ON coordination_schedule_links (second_user_id) WHERE revoked_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE coordination_schedule_links');
        $this->addSql('DROP TABLE coordination_schedule_invitations');
    }
}
