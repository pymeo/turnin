<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
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
    public function __construct(private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units, private WorkerAssignmentIdGenerator $ids, private WorkerAssignmentWriter $writer, private SwapPoolResolver $poolResolver, private ClockInterface $clock)
    {
    }

    public function __invoke(UpdateWorkerAssignment $command): void
    {
        $workplaceId = new WorkplaceId($command->workplaceId);
        $workplace = $this->workplaces->byId($workplaceId);
        if (null === $workplace || !$workplace->active()) {
            throw new InvalidArgumentException('The selected workplace is not available.');
        }
        $category = $this->categories->byId($command->staffCategoryId);
        if (null === $category || !$category->active()) {
            throw new InvalidArgumentException('The selected staff category is not available.');
        }
        if ($category->specialtyRequired() && null === $command->specialtyId) {
            throw new InvalidArgumentException('This category needs a specialty.');
        }
        if ($category->functionalAreaRequired() && null === $command->functionalArea && null === $command->organizationalUnitId) {
            throw new InvalidArgumentException('This category needs an area or an assigned unit.');
        }
        if (null !== $command->organizationalUnitId) {
            $unit = $this->units->byId($command->organizationalUnitId);
            if (null === $unit || !$unit->workplaceId()->equals($workplaceId)) {
                throw new InvalidArgumentException('The selected unit does not belong to the workplace.');
            }
        }
        $assignment = WorkerAssignment::create($this->ids->next(), $command->workerId, $workplaceId, $category->id(), $command->specialtyId, $command->organizationalUnitId, $command->functionalArea, $command->employerId, true, $this->clock->now());
        $key = $this->poolResolver->resolve($assignment);
        $this->writer->replacePrimary($assignment, $key, $this->ids->next(), $this->ids->next());
    }
}
