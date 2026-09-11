<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class CreateLocalOrganizationalUnit
{
    public function __construct(public string $workplaceId, public string $name)
    {
    }
}
