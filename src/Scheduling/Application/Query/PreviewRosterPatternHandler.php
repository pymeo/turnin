<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterPatternExpansion;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Application\ScheduleDraftPreviewFactory;

final readonly class PreviewRosterPatternHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private RosterPatternExpansion $expansion,
        private ScheduleDraftPreviewFactory $previews,
    ) {
    }

    public function __invoke(PreviewRosterPattern $query): ScheduleDraftPreview
    {
        $worker = $this->workspace->require($query->workerId, $query->assignmentId);
        $expanded = $this->expansion->expand($query->workerId, $query->patternId, $query->from, $query->to, $query->assignmentId);

        return $this->previews->build($worker, $expanded->draft, $query->policy);
    }
}
