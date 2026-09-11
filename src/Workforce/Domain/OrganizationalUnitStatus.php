<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum OrganizationalUnitStatus: string
{
    case VERIFIED = 'verified';
    case PENDING = 'pending';
    case INACTIVE = 'inactive';
}
