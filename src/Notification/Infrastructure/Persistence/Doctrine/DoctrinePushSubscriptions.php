<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence\Doctrine;

use App\Notification\Domain\PushSubscription;
use App\Notification\Domain\PushSubscriptions;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrinePushSubscriptions implements PushSubscriptions
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(PushSubscription $subscription): void
    {
        $this->connection->executeStatement(
            'INSERT INTO notification_push_subscriptions (id, recipient_id, endpoint, public_key, auth_token, active, created_at, updated_at) VALUES (:id, :recipient, :endpoint, :key, :auth, TRUE, :created, :created) ON CONFLICT (endpoint) DO UPDATE SET recipient_id = EXCLUDED.recipient_id, public_key = EXCLUDED.public_key, auth_token = EXCLUDED.auth_token, active = TRUE, updated_at = EXCLUDED.updated_at',
            ['id' => $subscription->id, 'recipient' => $subscription->recipientId, 'endpoint' => $subscription->endpoint, 'key' => $subscription->publicKey, 'auth' => $subscription->authToken, 'created' => $subscription->createdAt->format(DateTimeImmutable::ATOM)],
        );
    }

    public function activeFor(string $recipientId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM notification_push_subscriptions WHERE recipient_id = :recipient AND active = TRUE ORDER BY created_at', ['recipient' => $recipientId]);

        $subscriptions = [];
        foreach ($rows as $row) {
            $subscriptions[] = new PushSubscription($this->text($row['id'] ?? null), $this->text($row['recipient_id'] ?? null), $this->text($row['endpoint'] ?? null), $this->text($row['public_key'] ?? null), $this->text($row['auth_token'] ?? null), new DateTimeImmutable($this->text($row['created_at'] ?? null)));
        }

        return $subscriptions;
    }

    public function remove(string $id): void
    {
        $this->connection->executeStatement('UPDATE notification_push_subscriptions SET active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => $id]);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
