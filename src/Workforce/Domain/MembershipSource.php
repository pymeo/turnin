<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum MembershipSource: string
{
    case SELF_DECLARED = 'self_declared';
    case SUPERVISOR_CONFIRMED = 'supervisor_confirmed';
    case ADMIN_CONFIRMED = 'admin_confirmed';
    case IMPORTED = 'imported';
}
