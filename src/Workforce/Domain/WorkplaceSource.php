<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum WorkplaceSource: string
{
    case MINISTRY_PRIMARY_CARE = 'ministry_primary_care';
    case MINISTRY_URGENT_CARE = 'ministry_urgent_care';
    case MINISTRY_HOSPITALS = 'ministry_hospitals';

    public function label(): string
    {
        return match ($this) {
            self::MINISTRY_PRIMARY_CARE => 'Primary care',
            self::MINISTRY_URGENT_CARE => 'Urgent care',
            self::MINISTRY_HOSPITALS => 'Hospitals',
        };
    }
}
