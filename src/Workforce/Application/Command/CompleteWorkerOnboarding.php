<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class CompleteWorkerOnboarding
{
    /** @param list<string> $additionalDestinationIds */
    public function __construct(public string $workerId, public string $workplaceId, public string $staffCategoryId, public string $primaryDestinationId, public array $additionalDestinationIds = [], public ?string $specialtyId = null, public ?string $functionalArea = null, public ?string $employerId = null)
    {
    }
}
