<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

enum OrganizationalUnitStatus: string
{
    case VERIFIED = 'verified';
    case PENDING = 'pending';
    case INACTIVE = 'inactive';
}

final class OrganizationalUnit
{
    /** @param list<string> $aliases */
    private function __construct(private string $id, private WorkplaceId $workplaceId, private ?string $officialCode, private string $name, private array $aliases, private OrganizationalUnitKind $kind, private OrganizationalUnitStatus $status, private ?string $parentId, private DateTimeImmutable $createdAt, private DateTimeImmutable $updatedAt)
    {
    }

    /** @param list<string> $aliases */
    public static function create(string $id, WorkplaceId $workplaceId, string $name, ?string $officialCode, array $aliases, OrganizationalUnitKind $kind, OrganizationalUnitStatus $status, ?string $parentId, DateTimeImmutable $now): self
    {
        return new self($id, $workplaceId, $officialCode, trim($name), $aliases, $kind, $status, $parentId, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workplaceId(): WorkplaceId
    {
        return $this->workplaceId;
    }

    public function officialCode(): ?string
    {
        return $this->officialCode;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function kind(): OrganizationalUnitKind
    {
        return $this->kind;
    }

    public function status(): OrganizationalUnitStatus
    {
        return $this->status;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
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
