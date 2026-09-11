<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Workforce;

use App\Platform\Identity\Application\Command\ProtectPersonalIdentity;
use App\Platform\Identity\Application\Command\ProtectPersonalIdentityHandler;
use App\Platform\Identity\Application\Command\UpdatePersonalName;
use App\Platform\Identity\Application\Command\UpdatePersonalNameHandler;
use App\Platform\Identity\Application\Query\GetPersonalProfile;
use App\Platform\Identity\Application\Query\GetPersonalProfileHandler;
use App\Workforce\Domain\WorkerOnboardingIdentity;
use App\Workforce\Domain\WorkerOnboardingIdentityProfile;

final readonly class IdentityWorkerOnboardingIdentity implements WorkerOnboardingIdentity
{
    public function __construct(private GetPersonalProfileHandler $profiles, private UpdatePersonalNameHandler $names, private ProtectPersonalIdentityHandler $identities)
    {
    }

    public function profileFor(string $workerId): ?WorkerOnboardingIdentityProfile
    {
        $profile = ($this->profiles)(new GetPersonalProfile($workerId));

        return null === $profile ? null : new WorkerOnboardingIdentityProfile($profile->givenName, $profile->familyName, $profile->identityProvided);
    }

    public function rename(string $workerId, string $givenName, string $familyName): void
    {
        ($this->names)(new UpdatePersonalName($workerId, $givenName, $familyName));
    }

    public function protect(string $workerId, string $identityDocument, string $phone): void
    {
        ($this->identities)(new ProtectPersonalIdentity($workerId, $identityDocument, $phone));
    }
}
