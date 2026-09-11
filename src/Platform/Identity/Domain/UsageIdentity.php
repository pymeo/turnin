<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class UsageIdentity
{
    public function __construct(public string $id, public UserId $userId, public string $identityDocumentFingerprint, public string $phoneFingerprint, public IdentityEvidence $evidence, public DateTimeImmutable $createdAt)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $identityDocumentFingerprint) || !preg_match('/^[a-f0-9]{64}$/', $phoneFingerprint)) {
            throw new InvalidArgumentException('Usage identity fingerprints must be HMAC-SHA-256 hex values.');
        }
    }
}
