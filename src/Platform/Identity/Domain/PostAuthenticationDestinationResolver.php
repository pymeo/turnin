<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

final class PostAuthenticationDestinationResolver
{
    public function resolve(bool $worker, bool $supervisor): string
    {
        if ($worker) {
            return '/app';
        }

        return $supervisor ? '/supervisor' : '/onboarding';
    }
}
