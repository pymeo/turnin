<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

final class Employer
{
    private function __construct(private string $id, private WorkplaceId $workplaceId, private string $name, private bool $verified, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    public static function create(string $id, WorkplaceId $workplaceId, string $name, bool $verified, DateTimeImmutable $now): self
    {
        return new self($id, $workplaceId, trim($name), $verified, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workplaceId(): WorkplaceId
    {
        return $this->workplaceId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function verified(): bool
    {
        return $this->verified;
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
