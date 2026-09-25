<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stable swap agreement snapshots, in-app notifications and multi-device push subscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE swap_agreement_snapshots (proposal_id UUID NOT NULL, token_hash CHAR(64) NOT NULL, token_ciphertext TEXT NOT NULL, human_reference CHAR(6) NOT NULL, requested_date DATE NOT NULL, requested_segments JSONB NOT NULL, return_date DATE DEFAULT NULL, return_segments JSONB NOT NULL, workplace_name VARCHAR(255) NOT NULL, group_label VARCHAR(255) NOT NULL, reached_at TIMESTAMPTZ NOT NULL, revoked_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(proposal_id))');
        $this->addSql('ALTER TABLE swap_agreement_snapshots ADD CONSTRAINT swap_agreement_proposal_fk FOREIGN KEY (proposal_id) REFERENCES swap_proposals (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX swap_agreement_token_hash_unique ON swap_agreement_snapshots (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX swap_agreement_reference_unique ON swap_agreement_snapshots (human_reference)');
        $this->addSql("ALTER TABLE swap_agreement_snapshots ADD CONSTRAINT swap_agreement_return_ck CHECK ((return_date IS NULL AND return_segments = '[]'::jsonb) OR return_date IS NOT NULL)");

        $this->addSql('CREATE TABLE notification_user_notifications (id UUID NOT NULL, recipient_id UUID NOT NULL, event_id VARCHAR(160) NOT NULL, type VARCHAR(40) NOT NULL, title VARCHAR(180) NOT NULL, body VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL, read_at TIMESTAMPTZ DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE notification_user_notifications ADD CONSTRAINT notification_recipient_fk FOREIGN KEY (recipient_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX notification_event_recipient_unique ON notification_user_notifications (recipient_id, event_id)');
        $this->addSql('CREATE INDEX notification_unread_idx ON notification_user_notifications (recipient_id, created_at DESC) WHERE read_at IS NULL');

        $this->addSql('CREATE TABLE notification_push_subscriptions (id UUID NOT NULL, recipient_id UUID NOT NULL, endpoint TEXT NOT NULL, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE notification_push_subscriptions ADD CONSTRAINT push_subscription_recipient_fk FOREIGN KEY (recipient_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX push_subscription_endpoint_unique ON notification_push_subscriptions (endpoint)');
        $this->addSql('CREATE INDEX push_subscription_recipient_idx ON notification_push_subscriptions (recipient_id) WHERE active = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_push_subscriptions');
        $this->addSql('DROP TABLE notification_user_notifications');
        $this->addSql('DROP TABLE swap_agreement_snapshots');
    }
}
