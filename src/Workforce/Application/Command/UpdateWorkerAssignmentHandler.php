<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\MembershipSource;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\SwapPoolAccess;
use App\Workforce\Domain\SwapPoolResolver;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignmentIdGenerator;
use App\Workforce\Domain\WorkerAssignmentWriter;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceReader;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class UpdateWorkerAssignmentHandler
{
    public function __construct(private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units, private WorkerAssignmentIdGenerator $ids, private WorkerAssignmentWriter $writer, private SwapPoolResolver $resolver, private ClockInterface $clock)
    {
    }

    public function __invoke(UpdateWorkerAssignment $command): void
    {
        $workplaceId = new WorkplaceId($command->workplaceId);
        $workplace = $this->workplaces->byId($workplaceId);
        $category = $this->categories->byId($command->staffCategoryId);
        if (null === $workplace || !$workplace->active() || null === $category || !$category->active()) {
            throw new InvalidArgumentException('Selecciona un centro y una categoría válidos.');
        }
        if ($category->specialtyRequired() && null === $command->specialtyId) {
            throw new InvalidArgumentException('Esta categoría necesita una especialidad.');
        }
        $primaryUnit = $this->units->resolveSelection($workplaceId, $command->primaryDestinationId);
        $assignment = WorkerAssignment::create($this->ids->next(), $command->workerId, $workplaceId, $category->id(), $command->specialtyId, $primaryUnit->id(), $command->functionalArea, $command->employerId, true, $this->clock->now());
        $accesses = [new SwapPoolAccess($this->resolver->resolve($assignment), $this->ids->next(), $this->ids->next(), MembershipSource::SELF_DECLARED, true)];
        $seen = [$primaryUnit->id() => true];
        foreach (array_values(array_unique($command->additionalDestinationIds)) as $selectionId) {
            $unit = $this->units->resolveSelection($workplaceId, $selectionId);
            if (!isset($seen[$unit->id()])) {
                $seen[$unit->id()] = true;
                $accesses[] = new SwapPoolAccess($this->resolver->resolveForDestination($assignment, $unit->id()), $this->ids->next(), $this->ids->next(), MembershipSource::SELF_DECLARED, false);
            }
        }
        $this->writer->replacePrimary($assignment, $accesses);
    }
}
