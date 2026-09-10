<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

enum ExternalIdentityProvider: string
{
    case GOOGLE = 'google';
}
