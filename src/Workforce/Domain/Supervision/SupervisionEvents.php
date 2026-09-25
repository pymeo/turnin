<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use App\Workforce\Domain\Supervision\Event\SupervisionEvent;

/** Events leave the supervision flow through this port, after the commit. */
interface SupervisionEvents
{
    public function publishAfterCommit(SupervisionEvent $event): void;
}
