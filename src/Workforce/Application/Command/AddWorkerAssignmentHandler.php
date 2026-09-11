<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Application\WorkerAssignmentEditor;
use App\Workforce\Domain\WorkerAssignmentWriter;
use App\Workforce\Domain\WorkerOnboardingDrafts;

final readonly class AddWorkerAssignmentHandler
{
    public function __construct(private WorkerAssignmentEditor $editor, private WorkerAssignmentWriter $writer, private WorkerOnboardingDrafts $drafts)
    {
    }

    public function __invoke(AddWorkerAssignment $command): string
    {
        $plan = $this->editor->prepare($command->workerId, $command->workplaceId, $command->staffCategoryId, $command->primaryDestinationId, $command->additionalDestinationIds, $command->specialtyId, $command->functionalArea, $command->employerId);
        $this->writer->add($plan->assignment, $plan->accesses);
        $this->drafts->remove($command->workerId);

        return $plan->assignment->id();
    }
}
