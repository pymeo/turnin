<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

enum DestinationGroup: string
{
    case HABITUAL = 'habitual';
    case HOSPITALIZATION = 'hospitalization';
    case SUPPORT = 'support';
}
