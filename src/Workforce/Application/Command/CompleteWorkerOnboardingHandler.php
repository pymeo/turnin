<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Application\WorkerAssignmentEditor;
use App\Workforce\Domain\WorkerAssignmentWriter;
use App\Workforce\Domain\WorkerOnboardingDrafts;
use App\Workforce\Domain\WorkerProfileCompletion;

final readonly class CompleteWorkerOnboardingHandler
{
    public function __construct(private WorkerAssignmentEditor $editor, private WorkerAssignmentWriter $writer, private WorkerProfileCompletion $completion, private WorkerOnboardingDrafts $drafts)
    {
    }

    public function __invoke(CompleteWorkerOnboarding $command): void
    {
        $plan = $this->editor->prepare($command->workerId, $command->workplaceId, $command->staffCategoryId, $command->primaryDestinationId, $command->additionalDestinationIds, $command->specialtyId, $command->functionalArea, $command->employerId, true);
        $this->writer->replacePrimary($plan->assignment, $plan->accesses);
        $this->completion->complete($command->workerId);
        $this->drafts->remove($command->workerId);
    }
}
