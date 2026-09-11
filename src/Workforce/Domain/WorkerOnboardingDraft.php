<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

final readonly class WorkerOnboardingDraft
{
    /** @param list<OnboardingDestination> $additionalDestinations */
    public function __construct(public string $workerId, public ?string $workplaceId, public ?string $workplaceName, public ?string $staffCategoryId, public ?string $staffCategoryName, public ?OnboardingDestination $primaryDestination, public array $additionalDestinations, public DateTimeImmutable $updatedAt)
    {
    }

    /** @return list<string> */
    public function additionalDestinationIds(): array
    {
        return array_map(static fn (OnboardingDestination $destination): string => $destination->selectionId, $this->additionalDestinations);
    }
}
