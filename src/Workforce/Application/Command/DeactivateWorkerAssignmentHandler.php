<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\WorkerAssignmentWriter;
use Psr\Clock\ClockInterface;

final readonly class DeactivateWorkerAssignmentHandler
{
    public function __construct(private WorkerAssignmentWriter $writer, private ClockInterface $clock)
    {
    }

    public function __invoke(DeactivateWorkerAssignment $command): void
    {
        $this->writer->deactivate($command->workerId, $command->assignmentId, $this->clock->now());
    }
}
