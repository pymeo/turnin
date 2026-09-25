<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * Declared by Workforce, implemented by Identity.
 *
 * What a colleague needs to recognise a candidate — a name and a partly hidden
 * email — and the one capability flag Identity uses to route the account after
 * login. The flag is navigation, never authority: authority is a VERIFIED
 * SupervisorAssignment for a given pool.
 */
interface SupervisorProfiles
{
    public function displayName(string $userId): string;

    public function maskedEmail(string $userId): string;

    public function markSupervisorProfile(string $userId): void;
}
