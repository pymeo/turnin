<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

final class Specialty
{
    private function __construct(private string $id, private string $name, private string $categoryCode, private bool $active, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    public static function reference(string $id, string $name, string $categoryCode, DateTimeImmutable $now): self
    {
        return new self($id, $name, $categoryCode, true, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function categoryCode(): string
    {
        return $this->categoryCode;
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
