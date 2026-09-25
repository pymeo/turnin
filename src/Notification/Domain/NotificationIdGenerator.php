<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface NotificationIdGenerator
{
    public function next(): string;
}
