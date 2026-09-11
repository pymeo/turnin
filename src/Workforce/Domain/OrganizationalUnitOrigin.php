<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum OrganizationalUnitOrigin: string
{
    case OFFICIAL = 'official';
    case LOCAL = 'local';
    case TURNIN_REFERENCE = 'turnin_reference';
}
