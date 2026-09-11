<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignments;
use App\Workforce\Domain\WorkplaceReader;

final readonly class GetWorkerAssignmentsHandler
{
    public function __construct(private WorkerAssignments $assignments, private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units)
    {
    }

    /** @return list<WorkerAssignmentView> */
    public function __invoke(GetWorkerAssignments $query): array
    {
        return array_map(function (WorkerAssignment $assignment): WorkerAssignmentView {
            $workplace = $this->workplaces->byId($assignment->workplaceId());
            $category = $this->categories->byId($assignment->staffCategoryId());
            $unit = null === $assignment->organizationalUnitId() ? null : $this->units->byId($assignment->organizationalUnitId());

            return new WorkerAssignmentView($assignment->id(), $workplace?->name() ?? 'Centro de trabajo', $category?->name() ?? 'Categoría', $unit?->name() ?? '', $assignment->primary(), $assignment->active());
        }, $this->assignments->activeForWorker($query->workerId));
    }
}
