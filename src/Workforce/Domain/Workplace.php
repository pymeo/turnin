<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class Workplace
{
    private function __construct(
        private WorkplaceId $id,
        private WorkplaceSource $source,
        private string $externalId,
        private string $name,
        private WorkplaceType $type,
        private string $autonomousCommunity,
        private string $province,
        private string $municipality,
        private WorkplaceOwnership $ownership,
        private bool $active,
        private DateTimeImmutable $sourceUpdatedAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?string $healthArea = null,
        private ?string $basicHealthZone = null,
    ) {
    }

    public static function import(WorkplaceId $id, ImportedWorkplace $workplace, DateTimeImmutable $now): self
    {
        return new self(
            $id,
            $workplace->source,
            $workplace->externalId,
            $workplace->name,
            $workplace->type,
            $workplace->autonomousCommunity,
            $workplace->province,
            $workplace->municipality,
            $workplace->ownership,
            true,
            $now,
            $now,
            $now,
            $workplace->healthArea,
            $workplace->basicHealthZone,
        );
    }

    public function synchronize(ImportedWorkplace $workplace, DateTimeImmutable $now): bool
    {
        if ($this->source !== $workplace->source || $this->externalId !== $workplace->externalId) {
            throw new InvalidArgumentException('A workplace cannot change its external identity.');
        }

        $changed = !$this->active
            || $this->name !== $workplace->name
            || $this->type !== $workplace->type
            || $this->autonomousCommunity !== $workplace->autonomousCommunity
            || $this->province !== $workplace->province
            || $this->municipality !== $workplace->municipality
            || $this->healthArea !== $workplace->healthArea
            || $this->basicHealthZone !== $workplace->basicHealthZone;

        if (!$changed) {
            return false;
        }

        $this->name = $workplace->name;
        $this->type = $workplace->type;
        $this->autonomousCommunity = $workplace->autonomousCommunity;
        $this->province = $workplace->province;
        $this->municipality = $workplace->municipality;
        $this->ownership = $workplace->ownership;
        $this->healthArea = $workplace->healthArea;
        $this->basicHealthZone = $workplace->basicHealthZone;
        $this->active = true;
        $this->sourceUpdatedAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    public function deactivate(DateTimeImmutable $now): bool
    {
        if (!$this->active) {
            return false;
        }

        $this->active = false;
        $this->sourceUpdatedAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    public function id(): WorkplaceId
    {
        return $this->id;
    }

    public function source(): WorkplaceSource
    {
        return $this->source;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): WorkplaceType
    {
        return $this->type;
    }

    public function autonomousCommunity(): string
    {
        return $this->autonomousCommunity;
    }

    public function province(): string
    {
        return $this->province;
    }

    public function municipality(): string
    {
        return $this->municipality;
    }

    public function ownership(): WorkplaceOwnership
    {
        return $this->ownership;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function sourceUpdatedAt(): DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function healthArea(): ?string
    {
        return $this->healthArea;
    }

    public function basicHealthZone(): ?string
    {
        return $this->basicHealthZone;
    }
}
