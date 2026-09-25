<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * Who vouched for a supervisor.
 *
 * TEAM_VERIFIED is a community check — colleagues of the pool confirmed that
 * this is the person who usually handles their changes. It is **not** a legal
 * certification by the employer, and no screen may present it as one.
 * ORGANIZATION_VERIFIED is reserved for an official administrator assigning
 * supervisors directly; only rows migrated from the old administrative table
 * carry it today. See docs/adr/0014-team-verified-supervisors.md.
 */
enum SupervisorVerificationLevel: string
{
    case TEAM_VERIFIED = 'team_verified';
    case ORGANIZATION_VERIFIED = 'organization_verified';
}
