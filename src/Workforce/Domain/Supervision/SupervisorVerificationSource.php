<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

enum SupervisorVerificationSource: string
{
    /** The colleague who created the invitation, recorded when it is accepted. */
    case INVITATION = 'invitation';
    /** A colleague who answered the verification screen. */
    case TEAM_MEMBER = 'team_member';
}
