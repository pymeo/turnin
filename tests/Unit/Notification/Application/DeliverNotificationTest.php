<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationIdGenerator;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\PushDelivery;
use App\Notification\Domain\PushGateway;
use App\Notification\Domain\PushSubscription;
use App\Notification\Domain\PushSubscriptions;
use App\Notification\Domain\UserNotification;
use App\Notification\Domain\UserNotifications;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class DeliverNotificationTest extends TestCase
{
    public function test_in_app_is_idempotent_and_every_device_receives_the_deep_link(): void
    {
        $notifications = new MemoryNotifications();
        $subscriptions = new MemorySubscriptions([
            $this->subscription('phone', 'https://push.example/phone'),
            $this->subscription('desktop', 'https://push.example/desktop'),
        ]);
        $push = new RecordingPushGateway();
        $delivery = $this->delivery($notifications, $subscriptions, $push);

        $delivery->deliver('proposal:1', 'david', NotificationType::SWAP_PROPOSAL, 'Ana te propone un cambio', 'Te ha enviado 3 posibles turnos a cambio.', '/app/changes/proposals/1');
        $delivery->deliver('proposal:1', 'david', NotificationType::SWAP_PROPOSAL, 'Ana te propone un cambio', 'Te ha enviado 3 posibles turnos a cambio.', '/app/changes/proposals/1');

        self::assertCount(1, $notifications->rows);
        self::assertCount(2, $push->deliveries);
        self::assertSame(['/app/changes/proposals/1', '/app/changes/proposals/1'], array_column($push->deliveries, 'targetUrl'));
        self::assertSame(1, $notifications->unreadCount('david'));
    }

    public function test_team_news_stays_in_the_bell_and_never_reaches_the_phone(): void
    {
        $notifications = new MemoryNotifications();
        $push = new RecordingPushGateway();

        $this->delivery($notifications, new MemorySubscriptions([$this->subscription('phone', 'https://push.example/phone')]), $push)
            ->deliver('supervisor:1:verified', 'eva', NotificationType::SUPERVISOR_TEAM_UPDATE, 'Ya tenéis responsable en Turnin', 'El equipo de UCI ha verificado a Laura García como responsable.', '/app/equipo', false);

        self::assertCount(1, $notifications->rows);
        self::assertSame([], $push->deliveries);
    }

    public function test_expired_subscription_is_removed_and_push_failure_does_not_remove_the_notification(): void
    {
        $notifications = new MemoryNotifications();
        $subscriptions = new MemorySubscriptions([
            $this->subscription('expired', 'https://push.example/expired'),
            $this->subscription('broken', 'https://push.example/broken'),
        ]);
        $push = new RecordingPushGateway();
        $push->expiredEndpoints[] = 'https://push.example/expired';
        $push->failingEndpoints[] = 'https://push.example/broken';

        $this->delivery($notifications, $subscriptions, $push)->deliver('agreement:1', 'ana', NotificationType::SWAP_AGREEMENT, 'Cambio acordado', 'Habéis acordado el cambio.', '/app/changes/agreements/1');

        self::assertCount(1, $notifications->rows);
        self::assertSame(['expired'], $subscriptions->removed);
    }

    public function test_notification_infrastructure_failure_never_changes_the_agreement_result(): void
    {
        $notifications = new MemoryNotifications();
        $notifications->failWrites = true;

        $this->delivery($notifications, new MemorySubscriptions([]), new RecordingPushGateway())
            ->deliver('agreement:2', 'ana', NotificationType::SWAP_AGREEMENT, 'Cambio acordado', 'Habéis acordado el cambio.', '/app/changes/agreements/2');

        self::assertCount(0, $notifications->rows);
    }

    private function delivery(MemoryNotifications $notifications, MemorySubscriptions $subscriptions, RecordingPushGateway $push): DeliverNotification
    {
        return new DeliverNotification(
            $notifications,
            new class implements NotificationIdGenerator {
                private int $sequence = 0;

                public function next(): string
                {
                    return 'notification-'.(++$this->sequence);
                }
            },
            $subscriptions,
            $push,
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-24T18:42:00+02:00');
                }
            },
            new NullLogger(),
        );
    }

    private function subscription(string $id, string $endpoint): PushSubscription
    {
        return new PushSubscription($id, 'ana', $endpoint, 'public-key', 'auth-token', new DateTimeImmutable('2026-09-24T18:00:00+02:00'));
    }
}

final class MemoryNotifications implements UserNotifications
{
    /** @var array<string, UserNotification> */
    public array $rows = [];
    public bool $failWrites = false;

    public function addIfMissing(UserNotification $notification): bool
    {
        if ($this->failWrites) {
            throw new RuntimeException('database unavailable');
        }
        $key = $notification->recipientId().'|'.$notification->eventId();
        if (isset($this->rows[$key])) {
            return false;
        }
        $this->rows[$key] = $notification;

        return true;
    }

    public function byIdForRecipient(string $id, string $recipientId): ?UserNotification
    {
        foreach ($this->rows as $row) {
            if ($row->id() === $id && $row->recipientId() === $recipientId) {
                return $row;
            }
        }

        return null;
    }

    public function save(UserNotification $notification): void
    {
        $this->rows[$notification->recipientId().'|'.$notification->eventId()] = $notification;
    }

    public function recentFor(string $recipientId, int $limit = 40): array
    {
        return array_values(array_filter($this->rows, static fn (UserNotification $row): bool => $row->recipientId() === $recipientId));
    }

    public function unreadCount(string $recipientId): int
    {
        return \count(array_filter($this->rows, static fn (UserNotification $row): bool => $row->recipientId() === $recipientId && null === $row->readAt()));
    }

    public function markAllRead(string $recipientId, DateTimeImmutable $now): void
    {
        foreach ($this->rows as $row) {
            if ($row->recipientId() === $recipientId) {
                $row->markRead($now);
            }
        }
    }
}

final class MemorySubscriptions implements PushSubscriptions
{
    /** @param list<PushSubscription> $rows */
    public function __construct(private array $rows)
    {
    }

    /** @var list<string> */
    public array $removed = [];

    public function save(PushSubscription $subscription): void
    {
        $this->rows[] = $subscription;
    }

    public function activeFor(string $recipientId): array
    {
        return $this->rows;
    }

    public function remove(string $id): void
    {
        $this->removed[] = $id;
    }
}

final class RecordingPushGateway implements PushGateway
{
    /** @var list<array{endpoint: string, targetUrl: string}> */
    public array $deliveries = [];
    /** @var list<string> */
    public array $expiredEndpoints = [];
    /** @var list<string> */
    public array $failingEndpoints = [];

    public function send(PushSubscription $subscription, string $title, string $body, string $targetUrl): PushDelivery
    {
        if (\in_array($subscription->endpoint, $this->failingEndpoints, true)) {
            throw new RuntimeException('push service unavailable');
        }
        $this->deliveries[] = ['endpoint' => $subscription->endpoint, 'targetUrl' => $targetUrl];

        return new PushDelivery(true, \in_array($subscription->endpoint, $this->expiredEndpoints, true));
    }
}
