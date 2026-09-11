<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Identity;

use App\Swap\Domain\SwapIdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonySwapIdGenerator implements SwapIdGenerator
{
    public function next(): string
    {
        return (string) Uuid::v7();
    }
}
