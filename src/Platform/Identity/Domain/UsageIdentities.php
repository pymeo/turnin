<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface UsageIdentities
{
    public function byUserId(UserId $userId): ?UsageIdentity;

    public function save(UsageIdentity $identity): void;
}
