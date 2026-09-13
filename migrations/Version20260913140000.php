<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow deferred proposals without inventing a return shift';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind IN ('coverage', 'deferred') AND offered_roster_day_id IS NULL AND offered_work_date IS NULL) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_proposals DROP CONSTRAINT swap_proposals_kind_ck');
        $this->addSql("ALTER TABLE swap_proposals ADD CONSTRAINT swap_proposals_kind_ck CHECK ((kind = 'coverage' AND offered_roster_day_id IS NULL AND offered_work_date IS NULL) OR (kind = 'exchange' AND offered_roster_day_id IS NOT NULL AND offered_work_date IS NOT NULL))");
    }
}
