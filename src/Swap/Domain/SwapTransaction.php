<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use Closure;

interface SwapTransaction
{
    public function run(Closure $operation): mixed;
}
