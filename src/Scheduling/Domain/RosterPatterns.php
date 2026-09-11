<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

interface RosterPatterns
{
    /** @return list<RosterPattern> Most recent first. */
    public function forAssignment(string $workerAssignmentId): array;

    public function byId(string $workerAssignmentId, string $patternId): ?RosterPattern;

    public function save(RosterPattern $pattern): void;
}
