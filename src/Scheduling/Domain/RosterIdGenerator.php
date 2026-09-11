<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Identifiers are generated in Infrastructure (UUID v7 via symfony/uid); the
 * model only asks for the next one. One port for the whole context rather than
 * five identical ones — they would differ in nothing but their name.
 */
interface RosterIdGenerator
{
    public function next(): string;
}
