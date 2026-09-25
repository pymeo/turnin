<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/** Expiry is derived from `expiresAt`, not stored as a status. */
enum SupervisorInvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    /** Replaced by a newer invitation from the same colleague for the same pool. */
    case SUPERSEDED = 'superseded';
}
