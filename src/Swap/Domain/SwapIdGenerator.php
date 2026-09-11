<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface SwapIdGenerator
{
    public function next(): string;
}
