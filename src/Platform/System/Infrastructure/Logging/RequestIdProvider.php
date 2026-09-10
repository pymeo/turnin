<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Logging;

use Symfony\Component\Uid\Uuid;

/**
 * Holds the correlation id of the request (or CLI run) currently in flight.
 *
 * One tiny mutable service is what lets the log processor stay stateless and the
 * listener stay free of Monolog.
 */
final class RequestIdProvider
{
    /**
     * Inbound ids are echoed into logs, so they are constrained to a harmless
     * alphabet: an attacker must not be able to forge log lines through a header.
     */
    private const INBOUND_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    private ?string $requestId = null;

    public function current(): string
    {
        // A CLI run or an early boot log still deserves a correlation id.
        return $this->requestId ??= Uuid::v7()->toRfc4122();
    }

    public function adopt(?string $inbound): void
    {
        $this->requestId = (null !== $inbound && 1 === preg_match(self::INBOUND_PATTERN, $inbound))
            ? $inbound
            : Uuid::v7()->toRfc4122();
    }
}
