<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deferred exchange preferences and exchange balances';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_proposals ADD return_preference JSON DEFAULT NULL');
        $this->addSql('CREATE TABLE swap_exchange_balances (id UUID NOT NULL, creditor_worker_id UUID NOT NULL, owing_worker_id UUID NOT NULL, source_request_id UUID NOT NULL, source_roster_day_id UUID NOT NULL, earned_minutes INT NOT NULL, redeemed_minutes INT NOT NULL DEFAULT 0, reserved_minutes INT NOT NULL DEFAULT 0, status VARCHAR(24) NOT NULL, preference JSON DEFAULT NULL, expires_at TIMESTAMPTZ DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE swap_exchange_balances ADD CONSTRAINT swap_balance_source_fk FOREIGN KEY (source_request_id) REFERENCES swap_requests (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE swap_exchange_balances ADD CONSTRAINT swap_balance_minutes_ck CHECK (earned_minutes > 0 AND redeemed_minutes >= 0 AND reserved_minutes >= 0 AND redeemed_minutes + reserved_minutes <= earned_minutes)');
        $this->addSql('CREATE INDEX swap_balance_creditor_status ON swap_exchange_balances (creditor_worker_id, status)');
        $this->addSql('CREATE INDEX swap_balance_owing_status ON swap_exchange_balances (owing_worker_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE swap_exchange_balances');
        $this->addSql('ALTER TABLE swap_proposals DROP return_preference');
    }
}
