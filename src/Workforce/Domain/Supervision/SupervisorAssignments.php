<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

interface SupervisorAssignments
{
    /** Inserts or updates the assignment and upserts its verifications. */
    public function save(SupervisorAssignment $assignment): void;

    public function byId(string $id): ?SupervisorAssignment;

    public function byIdForUpdate(string $id): ?SupervisorAssignment;

    public function byVerificationTokenHash(string $tokenHash): ?SupervisorAssignment;

    public function byVerificationTokenHashForUpdate(string $tokenHash): ?SupervisorAssignment;

    /** Pending or verified — the partial unique index allows at most one. */
    public function activeFor(string $supervisorUserId, string $swapPoolId): ?SupervisorAssignment;

    /** @return list<SupervisorAssignment> pending or verified, in any pool */
    public function activeForSupervisor(string $supervisorUserId): array;

    /** @return list<SupervisorAssignment> pending or verified, oldest first */
    public function activeInPool(string $swapPoolId): array;
}
