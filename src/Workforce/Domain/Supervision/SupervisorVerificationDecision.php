<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

enum SupervisorVerificationDecision: string
{
    case CONFIRMED = 'confirmed';
    /** Recorded so the worker is not asked again; it never counts towards quorum. */
    case CANNOT_CONFIRM = 'cannot_confirm';
}
