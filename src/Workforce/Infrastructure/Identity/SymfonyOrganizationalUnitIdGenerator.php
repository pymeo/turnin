<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Identity;

use App\Workforce\Domain\OrganizationalUnitIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyOrganizationalUnitIdGenerator implements OrganizationalUnitIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
