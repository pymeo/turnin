<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Application\ScheduleDraftPreviewFactory;
use App\Scheduling\Domain\ScheduleDraftComposer;

final readonly class PreviewScheduleDraftHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private ScheduleDraftComposer $composer,
        private ScheduleDraftPreviewFactory $previews,
    ) {
    }

    public function __invoke(PreviewScheduleDraft $query): ScheduleDraftPreview
    {
        $worker = $this->workspace->require($query->workerId);
        $draft = $this->composer->compose($query->instructions, $this->workspace->presetsFor($worker), $query->source);

        return $this->previews->build($worker, $draft, $query->policy);
    }
}
