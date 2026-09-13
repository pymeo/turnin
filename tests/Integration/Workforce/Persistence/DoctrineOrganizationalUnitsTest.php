<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workforce\Persistence;

use App\Workforce\Domain\ImportedWorkplace;
use App\Workforce\Domain\OrganizationalUnitOrigin;
use App\Workforce\Domain\OrganizationalUnits;
use App\Workforce\Domain\OrganizationalUnitStatus;
use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\Workplaces;
use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineOrganizationalUnitsTest extends KernelTestCase
{
    private Connection $connection;
    private OrganizationalUnits $units;
    private WorkplaceId $workplaceId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $connection = $container->get(Connection::class);
        $units = $container->get(OrganizationalUnits::class);
        $workplaces = $container->get(Workplaces::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(OrganizationalUnits::class, $units);
        self::assertInstanceOf(Workplaces::class, $workplaces);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->units = $units;
        $this->workplaceId = new WorkplaceId('019b2000-0000-7000-8000-000000000101');
        $now = new DateTimeImmutable('2026-09-11T08:00:00Z');
        $workplaces->save(Workplace::import(
            $this->workplaceId,
            new ImportedWorkplace(WorkplaceSource::MINISTRY_HOSPITALS, 'units-test', 'Hospital de pruebas', WorkplaceType::HOSPITAL, 'Región', 'Provincia', 'Ciudad'),
            $now,
        ));
        $workplaces->deactivateMissingFrom(WorkplaceSource::MINISTRY_HOSPITALS, ['units-test'], $now);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_common_destinations_can_be_discovered_without_typing(): void
    {
        $names = array_map(static fn ($option): string => $option->name, $this->units->discover($this->workplaceId, '', 20));

        self::assertContains('Urgencias', $names);
        self::assertContains('UCI', $names);
        self::assertContains('Quirófano', $names);
        self::assertContains('Hospitalización', $names);
        self::assertContains('Equipo volante', $names);
    }

    public function test_floating_team_aliases_resolve_the_reference_unit(): void
    {
        foreach (['volante', 'volantes', 'correturnos', 'staff'] as $alias) {
            $results = $this->units->discover($this->workplaceId, $alias, 20);

            self::assertSame('Equipo volante', $results[0]->name ?? null, $alias);
        }

        $unit = $this->units->resolveSelection($this->workplaceId, 'reference:floating_team');
        self::assertSame(OrganizationalUnitStatus::VERIFIED, $unit->status());
        self::assertSame(OrganizationalUnitOrigin::TURNIN_REFERENCE, $unit->origin());
    }

    public function test_a_materialized_reference_destination_remains_featured_without_active_memberships(): void
    {
        $unit = $this->units->resolveSelection($this->workplaceId, 'reference:emergency');

        $results = $this->units->discover($this->workplaceId, '', 20);
        $emergency = array_values(array_filter($results, static fn ($option): bool => $option->selectionId === 'unit:'.$unit->id()));

        self::assertCount(1, $emergency);
        self::assertTrue($emergency[0]->featured);
    }

    public function test_a_manual_unit_is_local_pending_and_scoped_to_its_workplace(): void
    {
        $unit = $this->units->addLocal($this->workplaceId, 'Observación 2');

        self::assertSame(OrganizationalUnitStatus::PENDING, $unit->status());
        self::assertSame(OrganizationalUnitOrigin::LOCAL, $unit->origin());
        self::assertTrue($unit->workplaceId()->equals($this->workplaceId));
        self::assertSame($unit->id(), $this->units->resolveSelection($this->workplaceId, 'unit:'.$unit->id())->id());
    }
}
