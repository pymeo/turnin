<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * How a supervisor assignment started. Audit only: authority comes from
 * VERIFIED and nothing else, whatever the origin.
 */
enum SupervisorAssignmentOrigin: string
{
    /** A colleague invited this person, who accepted. */
    case INVITATION = 'invitation';
    /** A member of the pool asked the team to confirm them. */
    case SELF_REQUEST = 'self_request';
    /** Assigned administratively; today only rows migrated from the old table. */
    case ORGANIZATION = 'organization';
}
