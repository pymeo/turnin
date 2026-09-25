<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

enum RosterAgreementStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
