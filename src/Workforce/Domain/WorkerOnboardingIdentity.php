<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerOnboardingIdentity
{
    public function profileFor(string $workerId): ?WorkerOnboardingIdentityProfile;

    public function rename(string $workerId, string $givenName, string $familyName): void;

    public function protect(string $workerId, string $identityDocument, string $phone): void;
}
