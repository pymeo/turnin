<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

enum CalendarBlockType: string
{
    case PERSONAL = 'personal';
    case VACATION = 'vacation';
    case PERSONAL_LEAVE = 'personal_leave';
    case APPOINTMENT = 'appointment';
    case LEGAL = 'legal';
    case TRAVEL = 'travel';
    case OTHER = 'other';
}
