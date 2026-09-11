<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\Swap\Domain\SwapIdGenerator;

final class SequentialSwapIds implements SwapIdGenerator
{
    private int $next = 0;

    public function next(): string
    {
        return \sprintf('swap-%04d', ++$this->next);
    }
}
