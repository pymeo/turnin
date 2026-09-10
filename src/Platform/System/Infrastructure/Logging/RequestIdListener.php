<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Logging;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Gives every request a correlation id and hands it back on the response, so a
 * user-reported problem can be traced to the exact log lines that produced it.
 */
final readonly class RequestIdListener
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private RequestIdProvider $provider)
    {
    }

    // Runs before the router so that even a 404 is correlated.
    #[AsEventListener(event: RequestEvent::class, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->provider->adopt($event->getRequest()->headers->get(self::HEADER));
    }

    #[AsEventListener(event: ResponseEvent::class)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $this->provider->current());
    }
}
