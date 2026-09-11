<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preset colour and historical segment colour snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE scheduling_shift_presets ADD color_key VARCHAR(16) NOT NULL DEFAULT 'slate'");
        $this->addSql("UPDATE scheduling_shift_presets SET color_key = CASE kind WHEN 'morning' THEN 'amber' WHEN 'evening' THEN 'orange' WHEN 'night' THEN 'blue' WHEN 'long_day' THEN 'emerald' WHEN 'long_night' THEN 'blue' WHEN 'on_call' THEN 'violet' ELSE 'slate' END");
        $this->addSql("ALTER TABLE scheduling_roster_segments ADD color_key_snapshot VARCHAR(16) NOT NULL DEFAULT 'slate'");
        $this->addSql("UPDATE scheduling_roster_segments s SET color_key_snapshot = COALESCE(p.color_key, 'slate') FROM scheduling_shift_presets p WHERE p.id = s.shift_preset_id");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduling_roster_segments DROP color_key_snapshot');
        $this->addSql('ALTER TABLE scheduling_shift_presets DROP color_key');
    }
}
