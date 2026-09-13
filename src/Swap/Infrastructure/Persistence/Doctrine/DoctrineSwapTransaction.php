<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\Swap\Domain\SwapTransaction;
use Closure;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSwapTransaction implements SwapTransaction
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(Closure $operation): mixed
    {
        return $this->connection->transactional(static fn (Connection $connection): mixed => $operation());
    }
}
