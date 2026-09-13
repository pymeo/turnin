<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

interface ScheduleLinks
{
    public function save(ScheduleLink $link): void;

    public function activeFor(string $userId): ?ScheduleLink;

    public function activeBetween(string $userA, string $userB): ?ScheduleLink;
}
