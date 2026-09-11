<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\WorkerOnboardingDraft;
use App\Workforce\Domain\WorkerOnboardingDrafts;

final readonly class GetWorkerOnboardingDraftHandler
{
    public function __construct(private WorkerOnboardingDrafts $drafts)
    {
    }

    public function __invoke(GetWorkerOnboardingDraft $query): ?WorkerOnboardingDraft
    {
        return $this->drafts->byWorkerId($query->workerId);
    }
}
