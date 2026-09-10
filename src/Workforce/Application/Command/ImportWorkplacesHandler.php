<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceCatalogSource;
use App\Workforce\Domain\WorkplaceCatalogUnavailable;
use App\Workforce\Domain\WorkplaceIdGenerator;
use App\Workforce\Domain\Workplaces;
use Psr\Clock\ClockInterface;

final readonly class ImportWorkplacesHandler
{
    /**
     * @param iterable<WorkplaceCatalogSource> $workplaceCatalogSources
     */
    public function __construct(
        private iterable $workplaceCatalogSources,
        private Workplaces $workplaces,
        private WorkplaceIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ImportWorkplaces $command): WorkplaceImportReport
    {
        $now = $this->clock->now();
        $reports = [];

        foreach ($this->workplaceCatalogSources as $source) {
            try {
                $catalog = $source->fetch();
            } catch (WorkplaceCatalogUnavailable $exception) {
                $reports[] = WorkplaceSourceImportReport::failed($source->source(), $exception->getMessage());
                continue;
            }

            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $presentExternalIds = [];

            foreach ($catalog->workplaces as $imported) {
                $presentExternalIds[] = $imported->externalId;
                $workplace = $this->workplaces->ofSourceAndExternalId($imported->source, $imported->externalId);

                if (null === $workplace) {
                    $this->workplaces->save(Workplace::import($this->ids->next(), $imported, $now));
                    ++$created;
                    continue;
                }

                if ($workplace->synchronize($imported, $now)) {
                    $this->workplaces->save($workplace);
                    ++$updated;
                    continue;
                }

                ++$unchanged;
            }

            $reports[] = new WorkplaceSourceImportReport(
                $catalog->source,
                $created,
                $updated,
                $unchanged,
                $this->workplaces->deactivateMissingFrom($catalog->source, $presentExternalIds, $now),
                $catalog->rejectedRows,
            );
        }

        return new WorkplaceImportReport($reports, $this->workplaces->countActive());
    }
}
