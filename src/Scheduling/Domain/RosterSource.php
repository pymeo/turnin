<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * How a day came to be what it is. Traceability and interface copy only — never
 * an authorisation input: a day painted by hand and a day written by an
 * approved swap are equally binding.
 */
enum RosterSource: string
{
    case MANUAL = 'manual';
    case PATTERN = 'pattern';
    case VOICE = 'voice';
    case TEXT = 'text';
    case SWAP = 'swap';
    case IMPORT = 'import';
}
