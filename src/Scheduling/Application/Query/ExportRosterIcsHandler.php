<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\ExternalCalendar\IcsRosterExporter;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterDays;

final readonly class ExportRosterIcsHandler
{
    public function __construct(private RosterWorkspace $workspace, private RosterDays $days, private IcsRosterExporter $exporter)
    {
    }

    public function __invoke(ExportRosterIcs $query): string
    {
        $worker = $this->workspace->requireAssignment($query->workerId, $query->assignmentId);

        return $this->exporter->export($worker, $this->days->inRange($worker->assignmentId, $query->from, $query->to));
    }
}
