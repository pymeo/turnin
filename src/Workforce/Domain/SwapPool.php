<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

final class SwapPool
{
    private function __construct(private string $id, private SwapPoolKey $key, private bool $active, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    public static function for(SwapPoolKey $key, string $id, DateTimeImmutable $now): self
    {
        return new self($id, $key, true, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): SwapPoolKey
    {
        return $this->key;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
