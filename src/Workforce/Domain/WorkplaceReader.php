<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkplaceReader
{
    public function byId(WorkplaceId $id): ?Workplace;
}
