<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

use App\Platform\Identity\Domain\PersonalProfile;
use App\Platform\Identity\Domain\PersonalProfiles;
use App\Platform\Identity\Domain\UserId;
use Psr\Clock\ClockInterface;

final readonly class UpdatePersonalNameHandler
{
    public function __construct(private PersonalProfiles $profiles, private ClockInterface $clock)
    {
    }

    public function __invoke(UpdatePersonalName $command): void
    {
        $userId = new UserId($command->userId);
        $profile = $this->profiles->byUserId($userId);
        if (null === $profile) {
            $profile = PersonalProfile::start($userId, $command->givenName, $command->familyName, $this->clock->now());
        } else {
            $profile->rename($command->givenName, $command->familyName, $this->clock->now());
        }
        $this->profiles->save($profile);
    }
}
