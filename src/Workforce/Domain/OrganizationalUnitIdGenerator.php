<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface OrganizationalUnitIdGenerator
{
    public function next(): string;
}
