<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910154104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Workforce public healthcare workplace catalog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workforce_workplaces (source VARCHAR(40) NOT NULL, external_id VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(40) NOT NULL, autonomous_community VARCHAR(100) NOT NULL, province VARCHAR(100) NOT NULL, municipality VARCHAR(150) NOT NULL, ownership VARCHAR(20) NOT NULL, active BOOLEAN NOT NULL, source_updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, health_area VARCHAR(255) DEFAULT NULL, basic_health_zone VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_workplace_source_external_id ON workforce_workplaces (source, external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workforce_workplaces');
    }
}
