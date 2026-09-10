<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum OrganizationalUnitKind: string
{
    case FIXED_SERVICE = 'fixed_service';
    case FLOATING_TEAM = 'floating_team';
}
