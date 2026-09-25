<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * High-entropy tokens for the two links of this flow.
 *
 * The invitation token is random and only its hash is stored. The verification
 * token is derived from a server secret and the assignment id, so the
 * candidate can re-share it from her home without the plain value being stored;
 * only its hash is persisted for lookup. Neither grants anything: whoever opens
 * a verification link still has to be an active member of the pool.
 */
interface SupervisorLinkTokens
{
    /** @return array{token: string, hash: string} */
    public function newInvitationToken(): array;

    public function verificationToken(string $assignmentId): string;

    public function hash(string $token): string;
}
