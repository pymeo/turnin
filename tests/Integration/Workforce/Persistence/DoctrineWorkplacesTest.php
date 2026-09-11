<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workforce\Persistence;

use App\Workforce\Domain\ImportedWorkplace;
use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\Workplaces;
use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineWorkplacesTest extends KernelTestCase
{
    private Workplaces $workplaces;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->workplaces = $container->get(Workplaces::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM workforce_workplaces');
    }

    public function test_it_persists_by_external_identity_without_colliding_across_sources(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $hospital = $this->workplace('019b76da-a800-7000-8000-000000000001', WorkplaceSource::MINISTRY_HOSPITALS, '42', 'Hospital La Paz', 'Madrid', $now);
        $urgent = $this->workplace('019b76da-a800-7000-8000-000000000002', WorkplaceSource::MINISTRY_URGENT_CARE, '42', 'Urgencias La Paz', 'Madrid', $now);
        $this->workplaces->save($hospital);
        $this->workplaces->save($urgent);

        self::assertSame(0, $this->workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_HOSPITALS, ['42'], $now));
        self::assertSame(0, $this->workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_URGENT_CARE, ['42'], $now));

        self::assertSame($hospital->id()->value, $this->workplaces->ofSourceAndExternalId(WorkplaceSource::MINISTRY_HOSPITALS, '42')?->id()->value);
        self::assertSame($urgent->id()->value, $this->workplaces->ofSourceAndExternalId(WorkplaceSource::MINISTRY_URGENT_CARE, '42')?->id()->value);
        self::assertSame(2, $this->workplaces->countActive());
    }

    public function test_search_matches_all_name_tokens_and_location_but_never_inactive_rows(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $virgen = $this->workplace('019b76da-a800-7000-8000-000000000003', WorkplaceSource::MINISTRY_HOSPITALS, '1', 'Hospital Universitario Virgen de las Nieves', 'Granada', $now);
        $reina = $this->workplace('019b76da-a800-7000-8000-000000000004', WorkplaceSource::MINISTRY_HOSPITALS, '2', 'Hospital Reina Sofia', 'Córdoba', $now);
        $inactive = $this->workplace('019b76da-a800-7000-8000-000000000005', WorkplaceSource::MINISTRY_HOSPITALS, '3', 'Hospital Oculto', 'Granada', $now);
        $inactive->deactivate($now);
        $this->workplaces->save($virgen);
        $this->workplaces->save($reina);
        $this->workplaces->save($inactive);
        $this->workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_HOSPITALS, ['1', '2', '3'], $now);

        self::assertSame(['Hospital Universitario Virgen de las Nieves'], array_map(
            static fn (Workplace $workplace): string => $workplace->name(),
            $this->workplaces->searchActive('virgen nieves', 10),
        ));
        self::assertSame(['Hospital Reina Sofia'], array_map(
            static fn (Workplace $workplace): string => $workplace->name(),
            $this->workplaces->searchActive('Reina Sofía', 10),
        ));
        self::assertSame([], $this->workplaces->searchActive('Oculto', 10));
        self::assertCount(1, $this->workplaces->searchActive('Hospital', 1));
    }

    public function test_a_city_search_ranks_its_hospitals_before_alphabetical_province_matches(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $hospitalIds = [];
        foreach ([
            ['019b76da-a800-7000-8000-000000000101', '101', 'Hospital Clínico Universitario Virgen de la Arrixaca'],
            ['019b76da-a800-7000-8000-000000000102', '102', 'Hospital G. Universitario J.M. Morales Meseguer'],
        ] as [$id, $externalId, $name]) {
            $hospitalIds[] = $externalId;
            $this->workplaces->save($this->workplace($id, WorkplaceSource::MINISTRY_HOSPITALS, $externalId, $name, 'Murcia', $now, 'Murcia'));
        }
        $primaryCareIds = [];
        for ($index = 0; $index < 12; ++$index) {
            $externalId = 'pc-'.$index;
            $primaryCareIds[] = $externalId;
            $this->workplaces->save($this->workplace(\sprintf('019b76da-a800-7000-8000-%012d', 200 + $index), WorkplaceSource::MINISTRY_PRIMARY_CARE, $externalId, \sprintf('Centro %02d', $index), 'Abanilla', $now, 'Murcia'));
        }
        $this->workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_HOSPITALS, $hospitalIds, $now);
        $this->workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_PRIMARY_CARE, $primaryCareIds, $now);

        $names = array_map(static fn (Workplace $workplace): string => $workplace->name(), $this->workplaces->searchActive('Murcia', 5));

        self::assertSame([
            'Hospital Clínico Universitario Virgen de la Arrixaca',
            'Hospital G. Universitario J.M. Morales Meseguer',
        ], \array_slice($names, 0, 2));
    }

    private function workplace(string $id, WorkplaceSource $source, string $externalId, string $name, string $municipality, DateTimeImmutable $now, string $province = 'Provincia'): Workplace
    {
        return Workplace::import(
            new WorkplaceId($id),
            new ImportedWorkplace(
                $source,
                $externalId,
                $name,
                WorkplaceSource::MINISTRY_HOSPITALS === $source ? WorkplaceType::HOSPITAL : WorkplaceType::OUT_OF_HOSPITAL_URGENT_CARE,
                'Comunidad',
                $province,
                $municipality,
            ),
            $now,
        );
    }
}
