<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class UpdateWorkerAssignment
{
    public function __construct(public string $workerId, public string $workplaceId, public string $staffCategoryId, public ?string $specialtyId, public ?string $organizationalUnitId, public ?string $functionalArea, public ?string $employerId)
    {
    }
}
