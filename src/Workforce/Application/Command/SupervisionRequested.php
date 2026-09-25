<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

/**
 * What asking produced. Asking again while a request is running, or once
 * verified, is not an error: it points at the assignment that already exists.
 */
final readonly class SupervisionRequested
{
    public const string CREATED = 'created';
    public const string ALREADY_PENDING = 'already_pending';
    public const string ALREADY_VERIFIED = 'already_verified';

    public function __construct(public string $assignmentId, public string $outcome)
    {
    }
}
