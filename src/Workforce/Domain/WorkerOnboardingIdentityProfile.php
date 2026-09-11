<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final readonly class WorkerOnboardingIdentityProfile
{
    public function __construct(public string $givenName, public string $familyName, public bool $identityProvided)
    {
    }
}
