<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\OnboardingDestination;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\StaffCategories;
use App\Workforce\Domain\WorkerOnboardingDraft;
use App\Workforce\Domain\WorkerOnboardingDrafts;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceReader;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class SaveWorkerOnboardingDraftHandler
{
    public function __construct(private WorkplaceReader $workplaces, private StaffCategories $categories, private OrganizationalUnits $units, private WorkerOnboardingDrafts $drafts, private ClockInterface $clock)
    {
    }

    public function __invoke(SaveWorkerOnboardingDraft $command): WorkerOnboardingDraft
    {
        $workplaceId = null;
        $workplaceName = null;
        if (null !== $command->workplaceId && '' !== $command->workplaceId) {
            $workplaceId = new WorkplaceId($command->workplaceId);
            $workplace = $this->workplaces->byId($workplaceId);
            if (null === $workplace || !$workplace->active()) {
                throw new InvalidArgumentException('Selecciona un centro válido.');
            }
            $workplaceName = $workplace->name();
        }
        $categoryName = null;
        if (null !== $command->staffCategoryId) {
            $category = $this->categories->byId($command->staffCategoryId);
            if (null === $category) {
                throw new InvalidArgumentException('Selecciona una categoría válida.');
            }
            $categoryName = $category->name();
        }
        if ((null !== $command->primaryDestinationId || [] !== $command->additionalDestinationIds) && null === $workplaceId) {
            throw new InvalidArgumentException('Selecciona primero tu centro.');
        }
        $primary = null;
        $additional = [];
        if (null !== $workplaceId && null !== $command->primaryDestinationId && '' !== $command->primaryDestinationId) {
            $primaryUnit = $this->units->resolveSelection($workplaceId, $command->primaryDestinationId);
            $primary = new OnboardingDestination('unit:'.$primaryUnit->id(), $primaryUnit->name());
            foreach (array_values(array_unique($command->additionalDestinationIds)) as $selectionId) {
                $unit = $this->units->resolveSelection($workplaceId, $selectionId);
                $resolved = new OnboardingDestination('unit:'.$unit->id(), $unit->name());
                if ($resolved->selectionId !== $primary->selectionId) {
                    $additional[$resolved->selectionId] = $resolved;
                }
            }
        }
        $draft = new WorkerOnboardingDraft($command->workerId, $workplaceId ? (string) $workplaceId : null, $workplaceName, $command->staffCategoryId, $categoryName, $primary, array_values($additional), $this->clock->now());
        $this->drafts->save($draft);

        return $draft;
    }
}
