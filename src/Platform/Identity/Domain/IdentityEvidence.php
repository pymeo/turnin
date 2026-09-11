<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

enum IdentityEvidence: string
{
    case PROVIDED = 'provided';
    case VERIFIED = 'verified';
}
