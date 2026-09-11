<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface UsageIdentityIdGenerator
{
    public function next(): string;
}
