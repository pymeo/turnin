<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/** «UCI · Enfermería», «Hospital Virgen de las Nieves». Nothing else. */
final readonly class SwapPoolDescription
{
    public function __construct(public string $swapPoolId, public string $workplaceName, public string $teamLabel)
    {
    }

    public function shortLabel(): string
    {
        $parts = explode(' · ', $this->teamLabel);

        return '' !== $parts[0] ? $parts[0] : $this->workplaceName;
    }
}
