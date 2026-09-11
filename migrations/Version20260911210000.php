<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope swap-pool memberships and their primary marker to a worker assignment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX workforce_swap_membership_unique');
        $this->addSql('DROP INDEX workforce_primary_membership_unique');
        $this->addSql('CREATE UNIQUE INDEX workforce_swap_membership_unique ON workforce_swap_pool_memberships (swap_pool_id, worker_id, assignment_id)');
        $this->addSql('CREATE UNIQUE INDEX workforce_primary_membership_unique ON workforce_swap_pool_memberships (assignment_id) WHERE active = TRUE AND is_primary = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX workforce_swap_membership_unique');
        $this->addSql('DROP INDEX workforce_primary_membership_unique');
        $this->addSql('CREATE UNIQUE INDEX workforce_swap_membership_unique ON workforce_swap_pool_memberships (swap_pool_id, worker_id)');
        $this->addSql('CREATE UNIQUE INDEX workforce_primary_membership_unique ON workforce_swap_pool_memberships (worker_id) WHERE active = TRUE AND is_primary = TRUE');
    }
}
