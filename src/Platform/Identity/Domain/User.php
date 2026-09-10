<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DateTimeImmutable;

final readonly class User
{
    public function __construct(public UserId $id, public Email $email, public ?string $passwordHash, public bool $hasWorkerProfile, public bool $hasSupervisorProfile, public DateTimeImmutable $createdAt)
    {
    }
}
