<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Query;

final readonly class GetPersonalProfile
{
    public function __construct(public string $userId)
    {
    }
}
