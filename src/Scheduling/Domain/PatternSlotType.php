<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

enum PatternSlotType: string
{
    case SHIFT = 'shift';
    case REST = 'rest';
}
