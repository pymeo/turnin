<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class WorkerAssignmentView
{
    public function __construct(public string $id, public string $workplace, public string $category, public string $destination, public bool $primary, public bool $active)
    {
    }
}
