<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkplaceCatalogSource
{
    public function source(): WorkplaceSource;

    /**
     * Returns only after the complete source has been downloaded and parsed.
     * A failure must throw so callers never reconcile against a partial catalog.
     */
    public function fetch(): CompleteWorkplaceCatalog;
}
