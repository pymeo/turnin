<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface UserIdGenerator
{
    public function next(): UserId;
}
