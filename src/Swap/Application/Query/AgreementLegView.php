<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class AgreementLegView
{
    /** @param list<AgreementSegmentView> $segments */
    public function __construct(public string $eyebrow, public string $date, public string $dateIso, public array $segments, public string $location)
    {
    }
}
