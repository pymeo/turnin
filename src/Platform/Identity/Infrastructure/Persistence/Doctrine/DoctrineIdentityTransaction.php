<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\IdentityTransaction;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineIdentityTransaction implements IdentityTransaction
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        try {
            return $this->connection->transactional(static fn (Connection $connection): mixed => $operation());
        } catch (UniqueConstraintViolationException) {
            // Another callback may have created the user or provider link after
            // our reads. Re-evaluating once from committed state turns that race
            // into either the same identity or a controlled domain conflict.
            return $this->connection->transactional(static fn (Connection $connection): mixed => $operation());
        }
    }
}
