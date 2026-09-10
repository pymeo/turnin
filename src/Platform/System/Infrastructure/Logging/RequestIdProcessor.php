<?php

declare(strict_types=1);

namespace App\Platform\System\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log record with the current correlation id.
 *
 * Combined with the JSON formatter used in production, this is what makes
 * `request_id=…` a usable filter in whatever log store we end up with.
 */
final readonly class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(private RequestIdProvider $provider)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request_id'] = $this->provider->current();

        return $record;
    }
}
