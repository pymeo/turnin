<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\WorkplaceSource;

final readonly class WorkplaceSourceImportReport
{
    public function __construct(
        public WorkplaceSource $source,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $deactivated,
        public int $rejected,
        public ?string $failure = null,
    ) {
    }

    public static function failed(WorkplaceSource $source, string $reason): self
    {
        return new self($source, 0, 0, 0, 0, 0, $reason);
    }

    public function succeeded(): bool
    {
        return null === $this->failure;
    }
}
