<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationIdGenerator;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\PushGateway;
use App\Notification\Domain\PushSubscriptions;
use App\Notification\Domain\UserNotification;
use App\Notification\Domain\UserNotifications;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class DeliverNotification
{
    public function __construct(
        private UserNotifications $notifications,
        private NotificationIdGenerator $ids,
        private PushSubscriptions $subscriptions,
        private PushGateway $push,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(string $eventId, string $recipientId, NotificationType $type, string $title, string $body, string $targetUrl): void
    {
        $notification = UserNotification::create($this->ids->next(), $recipientId, $eventId, $type, $title, $body, $targetUrl, $this->clock->now());
        try {
            if (!$this->notifications->addIfMissing($notification)) {
                return;
            }
        } catch (Throwable $exception) {
            // Notifications are a post-commit side effect. A temporary failure
            // here must never turn an already persisted swap into a failed UI
            // action or trigger a retry of the agreement itself.
            $this->logger->error('The in-app notification could not be persisted.', [
                'event_id' => $eventId,
                'recipient_id' => $recipientId,
                'exception' => $exception,
            ]);

            return;
        }

        // The in-app row is durable before a best-effort external delivery.
        try {
            $subscriptions = $this->subscriptions->activeFor($recipientId);
        } catch (Throwable $exception) {
            $this->logger->warning('Push subscriptions could not be loaded; the in-app notification remains available.', [
                'notification_id' => $notification->id(),
                'exception' => $exception,
            ]);

            return;
        }

        foreach ($subscriptions as $subscription) {
            try {
                $result = $this->push->send($subscription, $title, $body, $targetUrl);
                if ($result->subscriptionExpired) {
                    $this->subscriptions->remove($subscription->id);
                }
            } catch (Throwable $exception) {
                $this->logger->warning('Web Push delivery failed; the in-app notification remains available.', [
                    'notification_id' => $notification->id(),
                    'subscription_id' => $subscription->id,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
