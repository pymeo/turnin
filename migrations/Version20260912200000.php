<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records the compatible worker who effectively covers a swap request.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_requests ADD covered_by_worker_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE swap_requests ADD covered_by_assignment_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_worker_fk FOREIGN KEY (covered_by_worker_id) REFERENCES identity_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_covered_assignment_fk FOREIGN KEY (covered_by_assignment_id) REFERENCES workforce_worker_assignments (id) ON DELETE SET NULL');
        $this->addSql("ALTER TABLE swap_requests ADD CONSTRAINT swap_requests_coverage_check CHECK ((status = 'covered') = (covered_by_worker_id IS NOT NULL AND covered_by_assignment_id IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_coverage_check');
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_worker_fk');
        $this->addSql('ALTER TABLE swap_requests DROP CONSTRAINT swap_requests_covered_assignment_fk');
        $this->addSql('ALTER TABLE swap_requests DROP covered_by_worker_id');
        $this->addSql('ALTER TABLE swap_requests DROP covered_by_assignment_id');
    }
}
