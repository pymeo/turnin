<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supervisor assignments remember how they started: invitation, self request or organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workforce_supervisor_assignments ADD origin VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE workforce_supervisor_assignments SET origin = CASE WHEN invitation_id IS NOT NULL THEN 'invitation' WHEN verification_level = 'organization_verified' THEN 'organization' ELSE 'self_request' END");
        $this->addSql('ALTER TABLE workforce_supervisor_assignments ALTER origin SET NOT NULL');
        $this->addSql("ALTER TABLE workforce_supervisor_assignments ADD CONSTRAINT workforce_supervisor_assignment_origin_ck CHECK (origin IN ('invitation', 'self_request', 'organization') AND (origin <> 'self_request' OR invitation_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workforce_supervisor_assignments DROP CONSTRAINT workforce_supervisor_assignment_origin_ck');
        $this->addSql('ALTER TABLE workforce_supervisor_assignments DROP origin');
    }
}
