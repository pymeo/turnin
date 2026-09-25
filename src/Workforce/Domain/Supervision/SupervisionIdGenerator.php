<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

interface SupervisionIdGenerator
{
    public function next(): string;
}
