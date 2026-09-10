<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerAssignmentIdGenerator
{
    public function next(): string;
}
