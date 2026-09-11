<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

final class SwapPoolResolver
{
    public function resolve(WorkerAssignment $assignment): SwapPoolKey
    {
        return new SwapPoolKey($assignment->workplaceId(), $assignment->staffCategoryId(), $assignment->specialtyId(), $assignment->organizationalUnitId(), $assignment->functionalArea(), $assignment->employerId());
    }

    public function resolveForDestination(WorkerAssignment $assignment, string $organizationalUnitId): SwapPoolKey
    {
        return new SwapPoolKey($assignment->workplaceId(), $assignment->staffCategoryId(), $assignment->specialtyId(), $organizationalUnitId, $assignment->functionalArea(), $assignment->employerId());
    }
}
