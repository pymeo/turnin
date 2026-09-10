<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\SwapPoolResolver;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignmentIdGenerator;
use App\Workforce\Domain\WorkerAssignmentWriter;
use App\Workforce\Domain\WorkerProfileCompletion;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceReader;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class CompleteWorkerOnboardingHandler
{
    public function __construct(private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units, private WorkerAssignmentIdGenerator $ids, private WorkerAssignmentWriter $writer, private SwapPoolResolver $resolver, private WorkerProfileCompletion $completion, private ClockInterface $clock)
    {
    }

    public function __invoke(CompleteWorkerOnboarding $command): void
    {
        $workplaceId = new WorkplaceId($command->workplaceId);
        $workplace = $this->workplaces->byId($workplaceId);
        $category = $this->categories->byId($command->staffCategoryId);
        if (null === $workplace || !$workplace->active() || null === $category) {
            throw new InvalidArgumentException('Selecciona un centro y una categoría válidos.');
        }
        if ($category->specialtyRequired() && null === $command->specialtyId) {
            throw new InvalidArgumentException('Esta categoría necesita una especialidad.');
        }
        if (null !== $command->organizationalUnitId) {
            $unit = $this->units->byId($command->organizationalUnitId);
            if (null === $unit || !$unit->workplaceId()->equals($workplaceId)) {
                throw new InvalidArgumentException('El destino no pertenece al centro seleccionado.');
            }
        }
        $assignment = WorkerAssignment::create($this->ids->next(), $command->workerId, $workplaceId, $category->id(), $command->specialtyId, $command->organizationalUnitId, $command->functionalArea, $command->employerId, true, $this->clock->now());
        $key = $this->resolver->resolve($assignment);
        $this->writer->replacePrimary($assignment, $key, $this->ids->next(), $this->ids->next());
        $this->completion->complete($command->workerId);
    }
}
