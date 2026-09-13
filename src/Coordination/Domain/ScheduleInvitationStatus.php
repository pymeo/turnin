<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

enum ScheduleInvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REVOKED = 'revoked';
}
