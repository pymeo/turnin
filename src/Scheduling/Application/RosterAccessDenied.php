<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use RuntimeException;

/**
 * Raised when the signed-in account has no worker assignment to write a roster
 * against. The calendar is always "my calendar": there is no code path that
 * takes a worker assignment id from the request.
 */
final class RosterAccessDenied extends RuntimeException
{
    public static function noAssignment(): self
    {
        return new self('Completa tu perfil laboral para usar el calendario.');
    }
}
