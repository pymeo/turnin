<?php

declare(strict_types=1);

namespace App\Workforce\Application;

use App\Workforce\Domain\MembershipSource;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\SwapPoolAccess;
use App\Workforce\Domain\SwapPoolResolver;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignmentIdGenerator;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceReader;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/** Shared labour-context flow for onboarding, adding and editing a workplace. */
final readonly class WorkerAssignmentEditor
{
    public function __construct(private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units, private WorkerAssignmentIdGenerator $ids, private SwapPoolResolver $resolver, private ClockInterface $clock)
    {
    }

    /** @param list<string> $additionalDestinationIds */
    public function prepare(string $workerId, string $workplace, string $categoryId, string $primaryDestinationId, array $additionalDestinationIds, ?string $specialtyId = null, ?string $functionalArea = null, ?string $employerId = null, bool $primary = false): WorkerAssignmentPlan
    {
        $workplaceId = new WorkplaceId($workplace);
        $foundWorkplace = $this->workplaces->byId($workplaceId);
        $category = $this->categories->byId($categoryId);
        if (null === $foundWorkplace || !$foundWorkplace->active() || null === $category || !$category->active()) {
            throw new InvalidArgumentException('Selecciona un centro y una categoría válidos.');
        }
        if ($category->specialtyRequired() && null === $specialtyId) {
            throw new InvalidArgumentException('Esta categoría necesita una especialidad.');
        }

        $unit = $this->units->resolveSelection($workplaceId, $primaryDestinationId);
        $assignment = WorkerAssignment::create($this->ids->next(), $workerId, $workplaceId, $category->id(), $specialtyId, $unit->id(), $functionalArea, $employerId, $primary, $this->clock->now());
        $accesses = [new SwapPoolAccess($this->resolver->resolve($assignment), $this->ids->next(), $this->ids->next(), MembershipSource::SELF_DECLARED, true)];
        $seen = [$unit->id() => true];
        foreach (array_values(array_unique($additionalDestinationIds)) as $selectionId) {
            $additional = $this->units->resolveSelection($workplaceId, $selectionId);
            if (!isset($seen[$additional->id()])) {
                $seen[$additional->id()] = true;
                $accesses[] = new SwapPoolAccess($this->resolver->resolveForDestination($assignment, $additional->id()), $this->ids->next(), $this->ids->next(), MembershipSource::SELF_DECLARED, false);
            }
        }

        return new WorkerAssignmentPlan($assignment, $accesses);
    }
}
