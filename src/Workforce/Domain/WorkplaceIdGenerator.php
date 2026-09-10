<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkplaceIdGenerator
{
    public function next(): WorkplaceId;
}
