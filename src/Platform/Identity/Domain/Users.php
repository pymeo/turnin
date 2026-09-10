<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface Users
{
    public function byEmail(Email $email): ?User;

    public function byId(UserId $id): ?User;

    public function save(User $user): void;

    public function markWorkerProfile(UserId $id): void;
}
