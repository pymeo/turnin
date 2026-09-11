<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Protected personal profiles, discoverable unit reference data, resumable onboarding and multi-pool membership provenance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_personal_profiles (user_id UUID NOT NULL, given_name VARCHAR(100) NOT NULL, family_name VARCHAR(160) NOT NULL, phone_encrypted TEXT DEFAULT NULL, identity_evidence VARCHAR(24) DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(user_id))');
        $this->addSql('ALTER TABLE identity_personal_profiles ADD CONSTRAINT identity_personal_profiles_user_fk FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE identity_usage_identities (id UUID NOT NULL, user_id UUID NOT NULL, identity_document_fingerprint VARCHAR(64) NOT NULL, phone_fingerprint VARCHAR(64) NOT NULL, evidence VARCHAR(24) NOT NULL, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE identity_usage_identities ADD CONSTRAINT identity_usage_identities_user_fk FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX identity_usage_user_unique ON identity_usage_identities (user_id)');
        $this->addSql('CREATE UNIQUE INDEX identity_document_fingerprint_unique ON identity_usage_identities (identity_document_fingerprint)');
        $this->addSql('CREATE UNIQUE INDEX identity_phone_fingerprint_unique ON identity_usage_identities (phone_fingerprint)');

        $this->addSql("ALTER TABLE workforce_organizational_units ADD normalized_name VARCHAR(160) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE workforce_organizational_units ADD origin VARCHAR(32) NOT NULL DEFAULT 'local'");
        $this->addSql("ALTER TABLE workforce_organizational_units ADD group_name VARCHAR(32) NOT NULL DEFAULT 'habitual'");
        $this->addSql("UPDATE workforce_organizational_units SET normalized_name = trim(regexp_replace(translate(lower(name), 'áéíóúüñ', 'aeiouun'), '\\s+', ' ', 'g')) WHERE normalized_name = ''");
        $this->addSql('DROP INDEX workforce_units_identity_unique');
        $this->addSql('CREATE UNIQUE INDEX workforce_units_identity_unique ON workforce_organizational_units (workplace_id, normalized_name)');

        $this->addSql('CREATE TABLE workforce_unit_definitions (code VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL, normalized_name VARCHAR(160) NOT NULL, aliases JSON NOT NULL, kind VARCHAR(32) NOT NULL, group_name VARCHAR(32) NOT NULL, featured BOOLEAN NOT NULL, display_order SMALLINT NOT NULL, active BOOLEAN NOT NULL, PRIMARY KEY(code))');
        $definitions = [
            ['emergency', 'Urgencias', ['urgencia', 'urgencias hospitalarias'], 'fixed_service', 'habitual', true],
            ['intensive_care', 'UCI', ['cuidados intensivos', 'uci adultos'], 'fixed_service', 'habitual', true],
            ['operating_room', 'Quirófano', ['quirofano', 'bloque quirúrgico', 'bloque quirurgico'], 'fixed_service', 'habitual', true],
            ['hospitalization', 'Hospitalización', ['hospitalizacion', 'planta', 'plantas'], 'fixed_service', 'hospitalization', true],
            ['floating_team', 'Equipo volante', ['volante', 'volantes', 'correturnos', 'staff'], 'floating_team', 'support', true],
            ['observation', 'Observación', ['observacion', 'observación urgencias', 'urgencias observacion'], 'fixed_service', 'habitual', false],
            ['short_stay', 'Unidad de corta estancia', ['corta estancia', 'uce'], 'fixed_service', 'hospitalization', false],
            ['internal_medicine', 'Medicina interna', ['medicina interna'], 'fixed_service', 'hospitalization', false],
            ['cardiology', 'Cardiología', ['cardiologia'], 'fixed_service', 'hospitalization', false],
            ['traumatology', 'Traumatología', ['traumatologia', 'trauma'], 'fixed_service', 'hospitalization', false],
            ['pediatrics', 'Pediatría', ['pediatria'], 'fixed_service', 'hospitalization', false],
            ['radiology', 'Radiodiagnóstico', ['radiodiagnostico', 'radiología', 'radiologia', 'rayos'], 'fixed_service', 'support', false],
            ['outpatient', 'Consultas externas', ['consultas', 'cc ee'], 'fixed_service', 'support', false],
            ['rehabilitation', 'Rehabilitación', ['rehabilitacion', 'fisio'], 'fixed_service', 'support', false],
            ['on_call_team', 'Retén', ['reten', 'pool de retén', 'pool reten'], 'floating_team', 'support', false],
            ['support_team', 'Equipo de apoyo', ['apoyo', 'equipo apoyo'], 'floating_team', 'support', false],
        ];
        foreach ($definitions as $position => [$code, $name, $aliases, $kind, $group, $featured]) {
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($name)) ?: mb_strtolower($name);
            $this->addSql('INSERT INTO workforce_unit_definitions (code, name, normalized_name, aliases, kind, group_name, featured, display_order, active) VALUES (:code, :name, :normalized, :aliases, :kind, :group_name, :featured, :position, TRUE)', ['code' => $code, 'name' => $name, 'normalized' => $normalized, 'aliases' => json_encode($aliases, \JSON_THROW_ON_ERROR), 'kind' => $kind, 'group_name' => $group, 'featured' => $featured, 'position' => $position + 1], ['featured' => 'boolean']);
        }

        $this->addSql("ALTER TABLE workforce_swap_pool_memberships ADD source VARCHAR(32) NOT NULL DEFAULT 'self_declared'");
        $this->addSql('ALTER TABLE workforce_swap_pool_memberships ADD is_primary BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('CREATE UNIQUE INDEX workforce_primary_membership_unique ON workforce_swap_pool_memberships (worker_id) WHERE active = TRUE AND is_primary = TRUE');

        $this->addSql('CREATE TABLE workforce_onboarding_drafts (worker_id UUID NOT NULL, workplace_id UUID DEFAULT NULL, workplace_name VARCHAR(255) DEFAULT NULL, staff_category_id UUID DEFAULT NULL, staff_category_name VARCHAR(160) DEFAULT NULL, primary_destination_id VARCHAR(128) DEFAULT NULL, primary_destination_name VARCHAR(160) DEFAULT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(worker_id))');
        $this->addSql('ALTER TABLE workforce_onboarding_drafts ADD CONSTRAINT workforce_onboarding_drafts_user_fk FOREIGN KEY (worker_id) REFERENCES identity_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE workforce_onboarding_draft_destinations (worker_id UUID NOT NULL, destination_id VARCHAR(128) NOT NULL, destination_name VARCHAR(160) NOT NULL, PRIMARY KEY(worker_id, destination_id))');
        $this->addSql('ALTER TABLE workforce_onboarding_draft_destinations ADD CONSTRAINT workforce_onboarding_draft_destinations_worker_fk FOREIGN KEY (worker_id) REFERENCES workforce_onboarding_drafts (worker_id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workforce_onboarding_draft_destinations');
        $this->addSql('DROP TABLE workforce_onboarding_drafts');
        $this->addSql('DROP INDEX workforce_primary_membership_unique');
        $this->addSql('ALTER TABLE workforce_swap_pool_memberships DROP is_primary');
        $this->addSql('ALTER TABLE workforce_swap_pool_memberships DROP source');
        $this->addSql('DROP TABLE workforce_unit_definitions');
        $this->addSql('DROP INDEX workforce_units_identity_unique');
        $this->addSql('CREATE UNIQUE INDEX workforce_units_identity_unique ON workforce_organizational_units (workplace_id, name)');
        $this->addSql('ALTER TABLE workforce_organizational_units DROP group_name');
        $this->addSql('ALTER TABLE workforce_organizational_units DROP origin');
        $this->addSql('ALTER TABLE workforce_organizational_units DROP normalized_name');
        $this->addSql('DROP TABLE identity_usage_identities');
        $this->addSql('DROP TABLE identity_personal_profiles');
    }
}
