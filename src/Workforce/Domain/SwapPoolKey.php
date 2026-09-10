<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final readonly class SwapPoolKey
{
    public function __construct(public WorkplaceId $workplaceId, public string $staffCategoryId, public ?string $specialtyId, public ?string $organizationalUnitId, public ?string $functionalArea, public ?string $employerId)
    {
    }

    public function fingerprint(): string
    {
        return implode(':', [(string) $this->workplaceId, $this->staffCategoryId, $this->specialtyId ?? '-', $this->organizationalUnitId ?? '-', $this->functionalArea ?? '-', $this->employerId ?? '-']);
    }
}
