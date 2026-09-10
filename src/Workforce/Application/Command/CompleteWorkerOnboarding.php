<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class CompleteWorkerOnboarding
{
    public function __construct(public string $workerId, public string $workplaceId, public string $staffCategoryId, public ?string $specialtyId = null, public ?string $organizationalUnitId = null, public ?string $functionalArea = 'General', public ?string $employerId = null)
    {
    }
}
