<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class WorkplaceId implements Stringable
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new InvalidArgumentException('A workplace id must be a UUID v7.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
