<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

interface CoordinationIdGenerator
{
    public function next(): string;
}
