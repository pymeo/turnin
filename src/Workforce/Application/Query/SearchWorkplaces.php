<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use InvalidArgumentException;

final readonly class SearchWorkplaces
{
    public string $term;

    public function __construct(string $term, public int $limit = 20)
    {
        $this->term = trim($term);

        if ('' === $this->term) {
            throw new InvalidArgumentException('A workplace search term cannot be empty.');
        }

        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('A workplace search limit must be between 1 and 50.');
        }
    }
}
