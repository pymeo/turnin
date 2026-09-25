<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * Where a person stands as the supervisor of one swap pool.
 *
 * Only VERIFIED carries authority. LEFT and REVOKED are terminal and keep the
 * row: who approved what, and when they were responsible, is history.
 */
enum SupervisorAssignmentStatus: string
{
    case PENDING_VERIFICATION = 'pending_verification';
    case VERIFIED = 'verified';
    case LEFT = 'left';
    case REVOKED = 'revoked';

    public function isActive(): bool
    {
        return self::PENDING_VERIFICATION === $this || self::VERIFIED === $this;
    }
}
