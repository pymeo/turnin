<?php

declare(strict_types=1);

namespace App\Swap\Application;

use RuntimeException;

/**
 * Raised whenever the signed-in worker is not entitled to what the request
 * asked for. Every message is deliberately the same shape and says nothing
 * about whether the thing exists: telling somebody "that request is in another
 * pool" confirms it exists and which centre it came from.
 */
final class SwapAccessDenied extends RuntimeException
{
    public static function noAssignment(): self
    {
        return new self('Completa tu perfil laboral para buscar cambios.');
    }

    public static function notAMember(): self
    {
        return new self('No perteneces a ese grupo de trabajo.');
    }

    public static function notYours(): self
    {
        return new self('Eso no es tuyo.');
    }
}
