<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\WorkerAssignmentWriter;
use Psr\Clock\ClockInterface;

final readonly class SetPrimaryWorkerAssignmentHandler
{
    public function __construct(private WorkerAssignmentWriter $writer, private ClockInterface $clock)
    {
    }

    public function __invoke(SetPrimaryWorkerAssignment $command): void
    {
        $this->writer->setPrimary($command->workerId, $command->assignmentId, $this->clock->now());
    }
}
