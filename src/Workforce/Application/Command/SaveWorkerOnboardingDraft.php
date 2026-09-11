<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class SaveWorkerOnboardingDraft
{
    /** @param list<string> $additionalDestinationIds */
    public function __construct(public string $workerId, public ?string $workplaceId, public ?string $staffCategoryId, public ?string $primaryDestinationId, public array $additionalDestinationIds = [])
    {
    }
}
