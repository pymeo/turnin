<?php

declare(strict_types=1);

namespace App\Platform\System\Domain;

/**
 * What losing a component actually costs.
 *
 * This is the only thing that decides whether an outage is fatal, which is why
 * each probe declares it rather than the reporting code guessing.
 */
enum ComponentCriticality: string
{
    /** Without it Turnin cannot answer correctly at all — e.g. the database. */
    case Required = 'required';

    /** Turnin still answers correctly, just slower — e.g. the cache. */
    case Optional = 'optional';
}
