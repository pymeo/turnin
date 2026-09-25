<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable evidence of what both people agreed. SwapProposal remains the
 * authority for lifecycle state; this snapshot preserves the shifts after the
 * roster moves them and owns the stable public sharing secret.
 */
final class SwapAgreementSnapshot
{
    /** @param list<AgreementSegment> $requestedSegments
     * @param list<AgreementSegment> $returnSegments
     */
    private function __construct(
        private readonly string $proposalId,
        private readonly string $publicToken,
        private readonly string $reference,
        private readonly string $requestedDate,
        private readonly array $requestedSegments,
        private readonly ?string $returnDate,
        private readonly array $returnSegments,
        private readonly string $workplaceName,
        private readonly string $groupLabel,
        private readonly DateTimeImmutable $reachedAt,
        private ?DateTimeImmutable $revokedAt,
    ) {
        if ('' === trim($proposalId) || \strlen($publicToken) < 43 || !preg_match('/^[A-Z0-9]{6}$/', $reference) || [] === $requestedSegments) {
            throw new InvalidArgumentException('La ficha del cambio necesita una referencia y un token seguros.');
        }
        if ((null === $returnDate) !== ([] === $returnSegments)) {
            throw new InvalidArgumentException('La segunda parte del cambio debe estar completa.');
        }
    }

    /** @param list<AgreementSegment> $requestedSegments
     * @param list<AgreementSegment> $returnSegments
     */
    public static function record(string $proposalId, string $publicToken, string $reference, string $requestedDate, array $requestedSegments, ?string $returnDate, array $returnSegments, string $workplaceName, string $groupLabel, DateTimeImmutable $reachedAt): self
    {
        return new self($proposalId, $publicToken, $reference, $requestedDate, $requestedSegments, $returnDate, $returnSegments, $workplaceName, $groupLabel, $reachedAt, null);
    }

    /** @param list<AgreementSegment> $requestedSegments
     * @param list<AgreementSegment> $returnSegments
     */
    public static function restore(string $proposalId, string $publicToken, string $reference, string $requestedDate, array $requestedSegments, ?string $returnDate, array $returnSegments, string $workplaceName, string $groupLabel, DateTimeImmutable $reachedAt, ?DateTimeImmutable $revokedAt): self
    {
        return new self($proposalId, $publicToken, $reference, $requestedDate, $requestedSegments, $returnDate, $returnSegments, $workplaceName, $groupLabel, $reachedAt, $revokedAt);
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function proposalId(): string
    {
        return $this->proposalId;
    }

    public function publicToken(): string
    {
        return $this->publicToken;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function requestedDate(): string
    {
        return $this->requestedDate;
    }

    /** @return list<AgreementSegment> */
    public function requestedSegments(): array
    {
        return $this->requestedSegments;
    }

    public function returnDate(): ?string
    {
        return $this->returnDate;
    }

    /** @return list<AgreementSegment> */
    public function returnSegments(): array
    {
        return $this->returnSegments;
    }

    public function workplaceName(): string
    {
        return $this->workplaceName;
    }

    public function groupLabel(): string
    {
        return $this->groupLabel;
    }

    public function reachedAt(): DateTimeImmutable
    {
        return $this->reachedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
