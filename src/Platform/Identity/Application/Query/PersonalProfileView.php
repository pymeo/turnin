<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Query;

final readonly class PersonalProfileView
{
    public function __construct(public string $givenName, public string $familyName, public bool $identityProvided)
    {
    }
}
