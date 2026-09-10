<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface ExternalIdentityIdGenerator
{
    public function next(): string;
}
