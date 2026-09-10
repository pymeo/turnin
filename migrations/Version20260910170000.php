<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workforce assignment reference data and conservative swap pools';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workforce_staff_categories (id UUID NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL, description VARCHAR(255) NOT NULL, aliases JSON NOT NULL, specialty_required BOOLEAN NOT NULL, functional_area_required BOOLEAN NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX workforce_staff_categories_code_unique ON workforce_staff_categories (code)');
        $this->addSql('CREATE TABLE workforce_organizational_units (id UUID NOT NULL, workplace_id UUID NOT NULL, official_code VARCHAR(120) DEFAULT NULL, name VARCHAR(255) NOT NULL, aliases JSON NOT NULL, kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, parent_id UUID DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX workforce_units_workplace_idx ON workforce_organizational_units (workplace_id, status, name)');
        $this->addSql('CREATE UNIQUE INDEX workforce_units_identity_unique ON workforce_organizational_units (workplace_id, name)');
        $this->addSql('CREATE TABLE workforce_employers (id UUID NOT NULL, workplace_id UUID NOT NULL, name VARCHAR(255) NOT NULL, verified BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX workforce_employers_identity_unique ON workforce_employers (workplace_id, name)');
        $this->addSql('CREATE TABLE workforce_worker_assignments (id UUID NOT NULL, worker_id UUID NOT NULL, workplace_id UUID NOT NULL, staff_category_id UUID NOT NULL, specialty_id UUID DEFAULT NULL, organizational_unit_id UUID DEFAULT NULL, functional_area VARCHAR(160) DEFAULT NULL, employer_id UUID DEFAULT NULL, primary_assignment BOOLEAN NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX workforce_worker_primary_unique ON workforce_worker_assignments (worker_id) WHERE primary_assignment = TRUE AND active = TRUE');
        $this->addSql('CREATE INDEX workforce_worker_assignments_worker_idx ON workforce_worker_assignments (worker_id, active)');
        $this->addSql('CREATE TABLE workforce_swap_pools (id UUID NOT NULL, workplace_id UUID NOT NULL, staff_category_id UUID NOT NULL, specialty_id UUID DEFAULT NULL, organizational_unit_id UUID DEFAULT NULL, functional_area VARCHAR(160) DEFAULT NULL, employer_id UUID DEFAULT NULL, fingerprint VARCHAR(600) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX workforce_swap_pool_fingerprint_unique ON workforce_swap_pools (fingerprint)');
        $this->addSql('CREATE TABLE workforce_swap_pool_memberships (id UUID NOT NULL, swap_pool_id UUID NOT NULL, worker_id UUID NOT NULL, assignment_id UUID NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX workforce_swap_membership_unique ON workforce_swap_pool_memberships (swap_pool_id, worker_id)');
        $now = '2026-09-10T00:00:00+00:00';
        $rows = [
            ['0198f8c0-0000-7000-8000-000000000001', 'nurse', 'Enfermero/a', 'Enfermería', ['enfermero', 'enfermera', 'due', 'ats'], false, false],
            ['0198f8c0-0000-7000-8000-000000000002', 'nursing_assistant', 'TCAE', 'Cuidados Auxiliares de Enfermería', ['tcae', 'auxiliar de enfermería', 'auxiliar de enfermeria', 'auxiliar de clínica', 'auxiliar de clinica'], false, false],
            ['0198f8c0-0000-7000-8000-000000000003', 'doctor', 'Médico/a', 'Medicina', ['médico', 'medico', 'médica', 'medica'], true, false],
            ['0198f8c0-0000-7000-8000-000000000004', 'orderly', 'Celador/a', 'Celador/a', ['celador', 'celadora'], false, false],
            ['0198f8c0-0000-7000-8000-000000000005', 'cleaner', 'Limpiador/a', 'Servicios de limpieza', ['limpiador', 'limpiadora', 'limpieza'], false, true],
            ['0198f8c0-0000-7000-8000-000000000006', 'administrative', 'Administrativo/a', 'Administración', ['administrativo', 'administrativa', 'auxiliar administrativo'], false, true],
            ['0198f8c0-0000-7000-8000-000000000007', 'technician', 'Técnico/a', 'Técnicos sanitarios y de soporte', ['técnico', 'tecnico', 'técnica', 'tecnica'], false, false],
            ['0198f8c0-0000-7000-8000-000000000008', 'cook', 'Cocina y alimentación', 'Cocina y alimentación', ['cocina', 'cocinero', 'alimentación'], false, true],
            ['0198f8c0-0000-7000-8000-000000000009', 'maintenance', 'Mantenimiento', 'Mantenimiento', ['mantenimiento'], false, true],
        ];
        foreach ($rows as [$id, $code, $name, $description, $aliases, $specialty, $area]) {
            $this->addSql(\sprintf("INSERT INTO workforce_staff_categories (id, code, name, description, aliases, specialty_required, functional_area_required, active, created_at, updated_at) VALUES ('%s', '%s', '%s', '%s', '%s'::json, %s, %s, TRUE, '%s', '%s')", $id, $code, str_replace("'", "''", $name), str_replace("'", "''", $description), json_encode($aliases, \JSON_THROW_ON_ERROR), $specialty ? 'TRUE' : 'FALSE', $area ? 'TRUE' : 'FALSE', $now, $now));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workforce_swap_pool_memberships');
        $this->addSql('DROP TABLE workforce_swap_pools');
        $this->addSql('DROP TABLE workforce_worker_assignments');
        $this->addSql('DROP TABLE workforce_employers');
        $this->addSql('DROP TABLE workforce_organizational_units');
        $this->addSql('DROP TABLE workforce_staff_categories');
    }
}
