<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Application;

use App\Workforce\Application\Command\ImportWorkplaces;
use App\Workforce\Application\Command\ImportWorkplacesHandler;
use App\Workforce\Domain\CompleteWorkplaceCatalog;
use App\Workforce\Domain\ImportedWorkplace;
use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceCatalogSource;
use App\Workforce\Domain\WorkplaceCatalogUnavailable;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceIdGenerator;
use App\Workforce\Domain\Workplaces;
use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImportWorkplacesHandlerTest extends TestCase
{
    public function test_it_is_idempotent_updates_and_reconciles_disappearances_and_reappearances(): void
    {
        $source = new MutableWorkplaceCatalogSource(WorkplaceSource::MINISTRY_PRIMARY_CARE);
        $source->workplaces = [$this->workplace(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id', 'Centro Uno', 'Granada')];
        $repository = new InMemoryWorkplaces();
        $handler = $this->handler([$source], $repository);

        $first = $handler(new ImportWorkplaces())->sources[0];
        self::assertSame([1, 0, 0], [$first->created, $first->updated, $first->unchanged]);

        $second = $handler(new ImportWorkplaces())->sources[0];
        self::assertSame([0, 0, 1], [$second->created, $second->updated, $second->unchanged]);

        $source->workplaces = [$this->workplace(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id', 'Centro Renombrado', 'Armilla')];
        $updated = $handler(new ImportWorkplaces())->sources[0];
        self::assertSame(1, $updated->updated);
        self::assertSame('Armilla', $repository->get(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id')->municipality());

        $source->workplaces = [$this->workplace(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'replacement', 'Centro Dos', 'Granada')];
        $disappeared = $handler(new ImportWorkplaces())->sources[0];
        self::assertSame(1, $disappeared->deactivated);
        self::assertFalse($repository->get(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id')->active());

        $source->workplaces = [$this->workplace(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id', 'Centro Renombrado', 'Armilla')];
        $reappeared = $handler(new ImportWorkplaces())->sources[0];
        self::assertSame(1, $reappeared->updated);
        self::assertTrue($repository->get(WorkplaceSource::MINISTRY_PRIMARY_CARE, 'same-id')->active());
    }

    public function test_identical_external_ids_from_two_sources_do_not_collide(): void
    {
        $primary = new MutableWorkplaceCatalogSource(WorkplaceSource::MINISTRY_PRIMARY_CARE);
        $primary->workplaces = [$this->workplace($primary->source(), '42', 'Centro de Salud', 'Granada')];
        $urgent = new MutableWorkplaceCatalogSource(WorkplaceSource::MINISTRY_URGENT_CARE);
        $urgent->workplaces = [$this->workplace($urgent->source(), '42', 'SUAP Granada', 'Granada')];
        $repository = new InMemoryWorkplaces();

        $report = $this->handler([$primary, $urgent], $repository)(new ImportWorkplaces());

        self::assertSame(2, $report->totalActive);
        self::assertNotSame(
            $repository->get($primary->source(), '42')->id()->value,
            $repository->get($urgent->source(), '42')->id()->value,
        );
    }

    public function test_a_failed_source_never_reconciles_its_existing_workplaces(): void
    {
        $source = new MutableWorkplaceCatalogSource(WorkplaceSource::MINISTRY_HOSPITALS);
        $source->workplaces = [$this->workplace($source->source(), '42', 'Hospital', 'Granada')];
        $repository = new InMemoryWorkplaces();
        $handler = $this->handler([$source], $repository);
        $handler(new ImportWorkplaces());
        $source->failure = 'HTTP 503';

        $report = $handler(new ImportWorkplaces());

        self::assertFalse($report->succeeded());
        self::assertSame('HTTP 503', $report->sources[0]->failure);
        self::assertTrue($repository->get($source->source(), '42')->active());
    }

    /**
     * @param list<WorkplaceCatalogSource> $sources
     */
    private function handler(array $sources, InMemoryWorkplaces $repository): ImportWorkplacesHandler
    {
        return new ImportWorkplacesHandler(
            $sources,
            $repository,
            new SequenceWorkplaceIds(),
            new MockClock('2026-09-10T12:00:00+00:00'),
        );
    }

    private function workplace(WorkplaceSource $source, string $id, string $name, string $municipality): ImportedWorkplace
    {
        return new ImportedWorkplace(
            $source,
            $id,
            $name,
            WorkplaceSource::MINISTRY_HOSPITALS === $source ? WorkplaceType::HOSPITAL : (WorkplaceSource::MINISTRY_PRIMARY_CARE === $source ? WorkplaceType::HEALTH_CENTER : WorkplaceType::OUT_OF_HOSPITAL_URGENT_CARE),
            'Andalucía',
            'Granada',
            $municipality,
        );
    }
}

final class MutableWorkplaceCatalogSource implements WorkplaceCatalogSource
{
    /** @var list<ImportedWorkplace> */
    public array $workplaces = [];
    public ?string $failure = null;

    public function __construct(private readonly WorkplaceSource $catalogSource)
    {
    }

    public function source(): WorkplaceSource
    {
        return $this->catalogSource;
    }

    public function fetch(): CompleteWorkplaceCatalog
    {
        if (null !== $this->failure) {
            throw new WorkplaceCatalogUnavailable($this->failure);
        }

        return new CompleteWorkplaceCatalog($this->catalogSource, $this->workplaces);
    }
}

final class SequenceWorkplaceIds implements WorkplaceIdGenerator
{
    private int $sequence = 0;

    public function next(): WorkplaceId
    {
        ++$this->sequence;

        return new WorkplaceId(\sprintf('019b76da-a800-7000-8000-%012d', $this->sequence));
    }
}

final class InMemoryWorkplaces implements Workplaces
{
    /** @var array<string, Workplace> */
    private array $workplaces = [];

    public function ofSourceAndExternalId(WorkplaceSource $source, string $externalId): ?Workplace
    {
        return $this->workplaces[$this->key($source, $externalId)] ?? null;
    }

    public function save(Workplace $workplace): void
    {
        $this->workplaces[$this->key($workplace->source(), $workplace->externalId())] = $workplace;
    }

    public function deactivateMissingFrom(WorkplaceSource $source, array $presentExternalIds, DateTimeImmutable $now): int
    {
        $present = array_fill_keys($presentExternalIds, true);
        $deactivated = 0;
        foreach ($this->workplaces as $workplace) {
            if ($source === $workplace->source() && !isset($present[$workplace->externalId()]) && $workplace->deactivate($now)) {
                ++$deactivated;
            }
        }

        return $deactivated;
    }

    public function searchActive(string $term, int $limit): array
    {
        return \array_slice(array_values(array_filter(
            $this->workplaces,
            static fn (Workplace $workplace): bool => $workplace->active() && str_contains(mb_strtolower($workplace->name()), mb_strtolower($term)),
        )), 0, $limit);
    }

    public function countActive(): int
    {
        return \count(array_filter($this->workplaces, static fn (Workplace $workplace): bool => $workplace->active()));
    }

    public function get(WorkplaceSource $source, string $externalId): Workplace
    {
        return $this->workplaces[$this->key($source, $externalId)];
    }

    private function key(WorkplaceSource $source, string $externalId): string
    {
        return $source->value.'|'.$externalId;
    }
}
