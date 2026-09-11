<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use RuntimeException;

final class ExternalSyncTokenExpired extends RuntimeException
{
}
