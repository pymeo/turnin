<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\OnboardingDestination;
use App\Workforce\Domain\WorkerOnboardingDraft;
use App\Workforce\Domain\WorkerOnboardingDrafts;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineWorkerOnboardingDrafts implements WorkerOnboardingDrafts
{
    public function __construct(private Connection $connection)
    {
    }

    public function byWorkerId(string $workerId): ?WorkerOnboardingDraft
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_onboarding_drafts WHERE worker_id = :worker', ['worker' => $workerId]);
        if (false === $row) {
            return null;
        }
        $additionalRows = $this->connection->fetchAllAssociative('SELECT destination_id, destination_name FROM workforce_onboarding_draft_destinations WHERE worker_id = :worker ORDER BY destination_name', ['worker' => $workerId]);
        $primaryId = $this->nullable($row['primary_destination_id'] ?? null);

        return new WorkerOnboardingDraft($this->text($row['worker_id'] ?? null), $this->nullable($row['workplace_id'] ?? null), $this->nullable($row['workplace_name'] ?? null), $this->nullable($row['staff_category_id'] ?? null), $this->nullable($row['staff_category_name'] ?? null), null === $primaryId ? null : new OnboardingDestination($primaryId, $this->text($row['primary_destination_name'] ?? null)), array_map(fn (array $destination): OnboardingDestination => new OnboardingDestination($this->text($destination['destination_id'] ?? null), $this->text($destination['destination_name'] ?? null)), $additionalRows), new DateTimeImmutable($this->text($row['updated_at'] ?? null)));
    }

    public function save(WorkerOnboardingDraft $draft): void
    {
        $this->connection->executeStatement('INSERT INTO workforce_onboarding_drafts (worker_id, workplace_id, workplace_name, staff_category_id, staff_category_name, primary_destination_id, primary_destination_name, updated_at) VALUES (:worker, :workplace, :workplace_name, :category, :category_name, :primary, :primary_name, :updated) ON CONFLICT (worker_id) DO UPDATE SET workplace_id = EXCLUDED.workplace_id, workplace_name = EXCLUDED.workplace_name, staff_category_id = EXCLUDED.staff_category_id, staff_category_name = EXCLUDED.staff_category_name, primary_destination_id = EXCLUDED.primary_destination_id, primary_destination_name = EXCLUDED.primary_destination_name, updated_at = EXCLUDED.updated_at', ['worker' => $draft->workerId, 'workplace' => $draft->workplaceId, 'workplace_name' => $draft->workplaceName, 'category' => $draft->staffCategoryId, 'category_name' => $draft->staffCategoryName, 'primary' => $draft->primaryDestination?->selectionId, 'primary_name' => $draft->primaryDestination?->name, 'updated' => $draft->updatedAt], ['updated' => 'datetime_immutable']);
        $this->connection->delete('workforce_onboarding_draft_destinations', ['worker_id' => $draft->workerId]);
        foreach ($draft->additionalDestinations as $destination) {
            $this->connection->insert('workforce_onboarding_draft_destinations', ['worker_id' => $draft->workerId, 'destination_id' => $destination->selectionId, 'destination_name' => $destination->name]);
        }
    }

    public function remove(string $workerId): void
    {
        $this->connection->delete('workforce_onboarding_drafts', ['worker_id' => $workerId]);
    }

    private function nullable(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
