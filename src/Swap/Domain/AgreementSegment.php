<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class AgreementSegment
{
    public function __construct(
        public string $start,
        public string $end,
        public int $durationMinutes,
        public string $label,
        public string $abbreviation,
        public string $color,
        public bool $endsNextDay,
    ) {
    }
}
