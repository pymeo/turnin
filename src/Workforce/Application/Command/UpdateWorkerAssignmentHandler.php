<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Application\WorkerAssignmentEditor;
use App\Workforce\Domain\WorkerAssignmentWriter;

final readonly class UpdateWorkerAssignmentHandler
{
    public function __construct(private WorkerAssignmentEditor $editor, private WorkerAssignmentWriter $writer)
    {
    }

    public function __invoke(UpdateWorkerAssignment $command): void
    {
        $plan = $this->editor->prepare($command->workerId, $command->workplaceId, $command->staffCategoryId, $command->primaryDestinationId, $command->additionalDestinationIds, $command->specialtyId, $command->functionalArea, $command->employerId, true);
        $this->writer->replacePrimary($plan->assignment, $plan->accesses);
    }
}
