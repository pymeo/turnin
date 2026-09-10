<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Workforce;

use App\Platform\Identity\Domain\UserId;
use App\Platform\Identity\Domain\Users;
use App\Workforce\Domain\WorkerProfileCompletion;

final readonly class IdentityWorkerProfileCompletion implements WorkerProfileCompletion
{
    public function __construct(private Users $users)
    {
    }

    public function complete(string $workerId): void
    {
        $this->users->markWorkerProfile(new UserId($workerId));
    }
}
