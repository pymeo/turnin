<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Query;

use App\Platform\Identity\Domain\PersonalProfiles;
use App\Platform\Identity\Domain\UsageIdentities;
use App\Platform\Identity\Domain\UserId;

final readonly class GetPersonalProfileHandler
{
    public function __construct(private PersonalProfiles $profiles, private UsageIdentities $usageIdentities)
    {
    }

    public function __invoke(GetPersonalProfile $query): ?PersonalProfileView
    {
        $userId = new UserId($query->userId);
        $profile = $this->profiles->byUserId($userId);
        if (null === $profile) {
            return null;
        }

        return new PersonalProfileView($profile->givenName(), $profile->familyName(), null !== $this->usageIdentities->byUserId($userId));
    }
}
