<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface PersonalProfiles
{
    public function byUserId(UserId $userId): ?PersonalProfile;

    public function save(PersonalProfile $profile): void;
}
