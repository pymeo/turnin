<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final readonly class OnboardingDestination
{
    public function __construct(public string $selectionId, public string $name)
    {
    }
}
