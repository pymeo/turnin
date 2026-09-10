<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface AuthenticatedWorker
{
    public function idForEmail(string $email): ?string;
}
