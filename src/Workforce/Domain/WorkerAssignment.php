<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class WorkerAssignment
{
    private function __construct(private string $id, private string $workerId, private WorkplaceId $workplaceId, private string $staffCategoryId, private ?string $specialtyId, private ?string $organizationalUnitId, private ?string $functionalArea, private ?string $employerId, private bool $primary, private bool $active, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    public static function create(string $id, string $workerId, WorkplaceId $workplaceId, string $staffCategoryId, ?string $specialtyId, ?string $organizationalUnitId, ?string $functionalArea, ?string $employerId, bool $primary, DateTimeImmutable $now): self
    {
        if ('' === trim($workerId) || '' === trim($staffCategoryId)) {
            throw new InvalidArgumentException('A worker assignment needs a worker and category.');
        }

        return new self($id, $workerId, $workplaceId, $staffCategoryId, $specialtyId, $organizationalUnitId, $functionalArea, $employerId, $primary, true, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function workplaceId(): WorkplaceId
    {
        return $this->workplaceId;
    }

    public function staffCategoryId(): string
    {
        return $this->staffCategoryId;
    }

    public function specialtyId(): ?string
    {
        return $this->specialtyId;
    }

    public function organizationalUnitId(): ?string
    {
        return $this->organizationalUnitId;
    }

    public function functionalArea(): ?string
    {
        return $this->functionalArea;
    }

    public function employerId(): ?string
    {
        return $this->employerId;
    }

    public function primary(): bool
    {
        return $this->primary;
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
