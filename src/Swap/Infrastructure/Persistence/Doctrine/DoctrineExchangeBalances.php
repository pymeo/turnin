<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\ExchangeBalanceStatus;
use App\Swap\Domain\ReturnPreference;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineExchangeBalances implements ExchangeBalances
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(ExchangeBalance $balance): void
    {
        $preference = $balance->preference();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO swap_exchange_balances (id, creditor_worker_id, owing_worker_id, source_request_id, source_roster_day_id, earned_minutes, redeemed_minutes, reserved_minutes, status, preference, expires_at, created_at, updated_at)
            VALUES (:id, :creditor, :owing, :request, :day, :earned, :redeemed, :reserved, :status, :preference, :expires, :created, :updated)
            ON CONFLICT (id) DO UPDATE SET redeemed_minutes = EXCLUDED.redeemed_minutes, reserved_minutes = EXCLUDED.reserved_minutes, status = EXCLUDED.status, updated_at = EXCLUDED.updated_at
            SQL, ['id' => $balance->id(), 'creditor' => $balance->creditorWorkerId(), 'owing' => $balance->owingWorkerId(), 'request' => $balance->sourceRequestId(), 'day' => $balance->sourceRosterDayId(), 'earned' => $balance->earnedMinutes(), 'redeemed' => $balance->redeemedMinutes(), 'reserved' => $balance->reservedMinutes(), 'status' => $balance->status()->value, 'preference' => null === $preference ? null : json_encode(['month' => $preference->month, 'kind' => $preference->shiftKind?->value, 'duration' => $preference->durationMinutes, 'weekdays' => $preference->preferredWeekdays], \JSON_THROW_ON_ERROR), 'expires' => $balance->expiresAt()?->format(DateTimeImmutable::ATOM), 'created' => $balance->createdAt()->format(DateTimeImmutable::ATOM), 'updated' => $balance->updatedAt()->format(DateTimeImmutable::ATOM)]);
    }

    public function byIdForUpdate(string $id): ?ExchangeBalance
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM swap_exchange_balances WHERE id = :id FOR UPDATE', ['id' => $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function byId(string $id): ?ExchangeBalance
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM swap_exchange_balances WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function bySourceRequest(string $requestId): ?ExchangeBalance
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM swap_exchange_balances WHERE source_request_id = :request', ['request' => $requestId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function involving(string $workerId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM swap_exchange_balances WHERE creditor_worker_id = :worker OR owing_worker_id = :worker ORDER BY created_at DESC', ['worker' => $workerId]);

        return array_map(fn (array $row): ExchangeBalance => $this->hydrate($row), $rows);
    }

    public function openBetween(string $creditorWorkerId, string $owingWorkerId): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM swap_exchange_balances WHERE creditor_worker_id = :creditor AND owing_worker_id = :owing AND status IN ('open', 'partially_redeemed') ORDER BY created_at", ['creditor' => $creditorWorkerId, 'owing' => $owingWorkerId]);

        return array_map(fn (array $row): ExchangeBalance => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExchangeBalance
    {
        $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $integer = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $raw = null === ($row['preference'] ?? null) ? null : json_decode($text($row['preference']), true, 512, \JSON_THROW_ON_ERROR);
        $preference = \is_array($raw) ? new ReturnPreference(\is_string($raw['month'] ?? null) ? $raw['month'] : null, \is_string($raw['kind'] ?? null) ? ShiftKind::from($raw['kind']) : null, \is_int($raw['duration'] ?? null) ? $raw['duration'] : null, \is_array($raw['weekdays'] ?? null) ? array_values(array_filter($raw['weekdays'], 'is_int')) : []) : null;

        return ExchangeBalance::restore($text($row['id']), $text($row['creditor_worker_id']), $text($row['owing_worker_id']), $text($row['source_request_id']), $text($row['source_roster_day_id']), $integer($row['earned_minutes']), $integer($row['redeemed_minutes']), $integer($row['reserved_minutes']), ExchangeBalanceStatus::from($text($row['status'])), $preference, new DateTimeImmutable($text($row['created_at'])), null === ($row['expires_at'] ?? null) ? null : new DateTimeImmutable($text($row['expires_at'])), new DateTimeImmutable($text($row['updated_at'])));
    }
}
