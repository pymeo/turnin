<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class StaffCategory
{
    /** @param list<string> $aliases */
    private function __construct(
        private string $id,
        private string $code,
        private string $name,
        private string $description,
        private array $aliases,
        private bool $specialtyRequired,
        private bool $functionalAreaRequired,
        private bool $active,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param list<string> $aliases */
    public static function reference(string $id, string $code, string $name, string $description, array $aliases, bool $specialtyRequired, bool $functionalAreaRequired, DateTimeImmutable $now): self
    {
        if ('' === trim($id) || '' === trim($code) || '' === trim($name)) {
            throw new InvalidArgumentException('A staff category needs an id, code and name.');
        }

        return new self($id, $code, $name, $description, $aliases, $specialtyRequired, $functionalAreaRequired, true, $now, $now);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function specialtyRequired(): bool
    {
        return $this->specialtyRequired;
    }

    public function functionalAreaRequired(): bool
    {
        return $this->functionalAreaRequired;
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
