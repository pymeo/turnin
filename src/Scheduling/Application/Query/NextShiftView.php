<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class NextShiftView
{
    public function __construct(
        public string $date,
        public string $when,
        public string $label,
        public string $hours,
        public string $tone,
        public bool $endsNextDay,
    ) {
    }
}
