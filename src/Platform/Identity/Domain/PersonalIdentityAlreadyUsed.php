<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DomainException;

final class PersonalIdentityAlreadyUsed extends DomainException
{
}
