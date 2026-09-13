<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Preserve the human destination behind assignments and exchange pools. */
final class Version20260912150000 extends AbstractMigration
{
    private const REPAIR_TIME = '2026-09-12T15:00:00+00:00';

    public function getDescription(): string
    {
        return 'Quarantine orphan destination references and enforce their integrity';
    }

    public function up(Schema $schema): void
    {
        // A missing unit cannot be reconstructed safely: its former name is not
        // stored anywhere else. Keep the historical rows, but make them
        // inactive and clear the broken optional reference before adding FKs.
        $this->addSql("UPDATE workforce_swap_pool_memberships m SET active = FALSE, updated_at = '".self::REPAIR_TIME."' WHERE m.active = TRUE AND (EXISTS (SELECT 1 FROM workforce_swap_pools p LEFT JOIN workforce_organizational_units u ON u.id = p.organizational_unit_id WHERE p.id = m.swap_pool_id AND p.organizational_unit_id IS NOT NULL AND u.id IS NULL) OR EXISTS (SELECT 1 FROM workforce_worker_assignments a LEFT JOIN workforce_organizational_units u ON u.id = a.organizational_unit_id WHERE a.id = m.assignment_id AND a.organizational_unit_id IS NOT NULL AND u.id IS NULL))");
        $this->addSql("UPDATE workforce_worker_assignments a SET active = FALSE, primary_assignment = FALSE, organizational_unit_id = NULL, updated_at = '".self::REPAIR_TIME."' WHERE a.organizational_unit_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM workforce_organizational_units u WHERE u.id = a.organizational_unit_id)");
        $this->addSql("UPDATE workforce_swap_pools p SET active = FALSE, organizational_unit_id = NULL, updated_at = '".self::REPAIR_TIME."' WHERE p.organizational_unit_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM workforce_organizational_units u WHERE u.id = p.organizational_unit_id)");

        $this->addSql('ALTER TABLE workforce_worker_assignments ADD CONSTRAINT workforce_assignment_unit_fk FOREIGN KEY (organizational_unit_id) REFERENCES workforce_organizational_units (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE workforce_swap_pools ADD CONSTRAINT workforce_swap_pool_unit_fk FOREIGN KEY (organizational_unit_id) REFERENCES workforce_organizational_units (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workforce_swap_pools DROP CONSTRAINT workforce_swap_pool_unit_fk');
        $this->addSql('ALTER TABLE workforce_worker_assignments DROP CONSTRAINT workforce_assignment_unit_fk');
    }
}
