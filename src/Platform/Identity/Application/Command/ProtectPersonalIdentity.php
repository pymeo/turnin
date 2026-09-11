<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

final readonly class ProtectPersonalIdentity
{
    public function __construct(public string $userId, public string $identityDocument, public string $phone)
    {
    }
}
