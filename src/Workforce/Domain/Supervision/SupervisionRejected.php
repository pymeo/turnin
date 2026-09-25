<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use DomainException;

/** A supervision rule said no. The message is written for the person who acted. */
final class SupervisionRejected extends DomainException
{
}
