<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

interface ScheduleInvitations
{
    public function save(ScheduleInvitation $invitation): void;

    public function byTokenHash(string $tokenHash): ?ScheduleInvitation;
}
