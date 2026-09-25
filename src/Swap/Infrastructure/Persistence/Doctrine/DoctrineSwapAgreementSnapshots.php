<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\Swap\Domain\AgreementSegment;
use App\Swap\Domain\AgreementTokenCipher;
use App\Swap\Domain\SwapAgreementSnapshot;
use App\Swap\Domain\SwapAgreementSnapshots;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSwapAgreementSnapshots implements SwapAgreementSnapshots
{
    public function __construct(private Connection $connection, private AgreementTokenCipher $cipher)
    {
    }

    public function saveIfMissing(SwapAgreementSnapshot $snapshot): SwapAgreementSnapshot
    {
        $this->connection->executeStatement(
            'INSERT INTO swap_agreement_snapshots (proposal_id, token_hash, token_ciphertext, human_reference, requested_date, requested_segments, return_date, return_segments, workplace_name, group_label, reached_at, revoked_at) VALUES (:proposal, :hash, :cipher, :reference, :requested_date, :requested_segments, :return_date, :return_segments, :workplace, :group_label, :reached, NULL) ON CONFLICT (proposal_id) DO NOTHING',
            $this->parameters($snapshot),
        );

        return $this->byProposal($snapshot->proposalId()) ?? $snapshot;
    }

    public function byProposal(string $proposalId): ?SwapAgreementSnapshot
    {
        return $this->one('SELECT * FROM swap_agreement_snapshots WHERE proposal_id = :proposal', ['proposal' => $proposalId]);
    }

    public function byPublicToken(string $publicToken): ?SwapAgreementSnapshot
    {
        return $this->one('SELECT * FROM swap_agreement_snapshots WHERE token_hash = :hash', ['hash' => hash('sha256', $publicToken)]);
    }

    public function save(SwapAgreementSnapshot $snapshot): void
    {
        $this->connection->executeStatement('UPDATE swap_agreement_snapshots SET revoked_at = :revoked WHERE proposal_id = :proposal', ['revoked' => $snapshot->revokedAt()?->format(DateTimeImmutable::ATOM), 'proposal' => $snapshot->proposalId()]);
    }

    /** @return array<string, mixed> */
    private function parameters(SwapAgreementSnapshot $snapshot): array
    {
        return [
            'proposal' => $snapshot->proposalId(), 'hash' => hash('sha256', $snapshot->publicToken()), 'cipher' => $this->cipher->encrypt($snapshot->publicToken()),
            'reference' => $snapshot->reference(), 'requested_date' => $snapshot->requestedDate(), 'requested_segments' => $this->encode($snapshot->requestedSegments()),
            'return_date' => $snapshot->returnDate(), 'return_segments' => $this->encode($snapshot->returnSegments()), 'workplace' => $snapshot->workplaceName(),
            'group_label' => $snapshot->groupLabel(), 'reached' => $snapshot->reachedAt()->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @param list<AgreementSegment> $segments */
    private function encode(array $segments): string
    {
        return json_encode(array_map(static fn (AgreementSegment $segment): array => (array) $segment, $segments), \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $parameters */
    private function one(string $sql, array $parameters): ?SwapAgreementSnapshot
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);
        if (false === $row) {
            return null;
        }

        return SwapAgreementSnapshot::restore(
            $this->text($row['proposal_id'] ?? null), $this->cipher->decrypt($this->text($row['token_ciphertext'] ?? null)), $this->text($row['human_reference'] ?? null),
            $this->text($row['requested_date'] ?? null), $this->decode($this->text($row['requested_segments'] ?? null)), null === ($row['return_date'] ?? null) ? null : $this->text($row['return_date'] ?? null),
            $this->decode($this->text($row['return_segments'] ?? null)), $this->text($row['workplace_name'] ?? null), $this->text($row['group_label'] ?? null), new DateTimeImmutable($this->text($row['reached_at'] ?? null)),
            null === ($row['revoked_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['revoked_at'] ?? null)),
        );
    }

    /** @return list<AgreementSegment> */
    private function decode(string $json): array
    {
        $rows = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($rows)) {
            return [];
        }

        $segments = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $segments[] = new AgreementSegment($this->text($row['start'] ?? null), $this->text($row['end'] ?? null), $this->number($row['durationMinutes'] ?? null), $this->text($row['label'] ?? null), $this->text($row['abbreviation'] ?? null), $this->text($row['color'] ?? null), true === ($row['endsNextDay'] ?? false));
        }

        return $segments;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
