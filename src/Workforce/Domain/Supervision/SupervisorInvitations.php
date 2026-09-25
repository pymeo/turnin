<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

interface SupervisorInvitations
{
    public function save(SupervisorInvitation $invitation): void;

    public function byTokenHash(string $tokenHash): ?SupervisorInvitation;

    public function byTokenHashForUpdate(string $tokenHash): ?SupervisorInvitation;

    /** @return list<SupervisorInvitation> still pending, from this colleague for this pool */
    public function pendingFrom(string $invitedByWorkerId, string $swapPoolId): array;
}
