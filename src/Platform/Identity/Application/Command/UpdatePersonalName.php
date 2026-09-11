<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

final readonly class UpdatePersonalName
{
    public function __construct(public string $userId, public string $givenName, public string $familyName)
    {
    }
}
