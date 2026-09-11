<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerOnboardingDrafts
{
    public function byWorkerId(string $workerId): ?WorkerOnboardingDraft;

    public function save(WorkerOnboardingDraft $draft): void;

    public function remove(string $workerId): void;
}
