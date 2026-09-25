<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

enum RosterSwapRole: string
{
    case GIVEN_AWAY = 'given_away';
    case TAKEN_FROM_COLLEAGUE = 'taken_from_colleague';
}
