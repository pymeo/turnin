<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class AgreementSegmentView
{
    public function __construct(public string $hours, public string $duration, public string $label, public bool $endsNextDay)
    {
    }
}
