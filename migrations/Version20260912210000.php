<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keeps covered request invariants intact when a participating account or assignment is erased.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_worker_fk');
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_assignment_fk');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_worker_fk FOREIGN KEY (covered_by_worker_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_assignment_fk FOREIGN KEY (covered_by_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_worker_fk');
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_assignment_fk');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_worker_fk FOREIGN KEY (covered_by_worker_id) REFERENCES identity_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_assignment_fk FOREIGN KEY (covered_by_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE SET NULL');
    }
}
