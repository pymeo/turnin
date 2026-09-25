<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Domain\PushDelivery;
use App\Notification\Domain\PushGateway;
use App\Notification\Domain\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final readonly class MinishlinkPushGateway implements PushGateway
{
    public function __construct(private string $vapidSubject, private string $vapidPublicKey, private string $vapidPrivateKey)
    {
    }

    public function send(PushSubscription $subscription, string $title, string $body, string $targetUrl): PushDelivery
    {
        if ('' === trim($this->vapidPublicKey) || '' === trim($this->vapidPrivateKey)) {
            return new PushDelivery(false);
        }
        $webPush = new WebPush(['VAPID' => ['subject' => $this->vapidSubject, 'publicKey' => $this->vapidPublicKey, 'privateKey' => $this->vapidPrivateKey]]);
        $report = $webPush->sendOneNotification(
            new Subscription($subscription->endpoint, $subscription->publicKey, $subscription->authToken, 'aes128gcm'),
            json_encode(['title' => $title, 'body' => $body, 'targetUrl' => $targetUrl], \JSON_THROW_ON_ERROR),
            ['TTL' => 86400, 'urgency' => 'normal'],
        );

        return new PushDelivery($report->isSuccess(), $report->isSubscriptionExpired());
    }
}
