<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use DateTimeImmutable;
use InvalidArgumentException;

/** One colleague's answer about one candidate. At most one per colleague. */
final readonly class SupervisorVerification
{
    public function __construct(
        public string $id,
        public string $verifierWorkerId,
        public SupervisorVerificationDecision $decision,
        public SupervisorVerificationSource $source,
        public DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id) || '' === trim($verifierWorkerId)) {
            throw new InvalidArgumentException('A verification needs an id and a verifier.');
        }
        if (SupervisorVerificationSource::INVITATION === $source && SupervisorVerificationDecision::CONFIRMED !== $decision) {
            throw new InvalidArgumentException('An invitation can only count as a confirmation.');
        }
    }

    public function counts(): bool
    {
        return SupervisorVerificationDecision::CONFIRMED === $this->decision;
    }
}
