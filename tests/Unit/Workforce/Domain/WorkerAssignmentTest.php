<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Domain;

use App\Workforce\Domain\OrganizationalUnit;
use App\Workforce\Domain\OrganizationalUnitKind;
use App\Workforce\Domain\OrganizationalUnitStatus;
use App\Workforce\Domain\SwapPoolResolver;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkplaceId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WorkerAssignmentTest extends TestCase
{
    public function test_a_floating_assignment_has_a_pool_key_separate_from_the_temporary_shift_location(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $workplace = new WorkplaceId('0198f8c0-0000-7000-8000-000000000010');
        $unit = OrganizationalUnit::create('0198f8c0-0000-7000-8000-000000000011', $workplace, 'Equipo volante', null, ['volantes', 'correturnos', 'retén', 'equipo de apoyo'], OrganizationalUnitKind::FLOATING_TEAM, OrganizationalUnitStatus::VERIFIED, null, $now);
        $assignment = WorkerAssignment::create('0198f8c0-0000-7000-8000-000000000012', '0198f8c0-0000-7000-8000-000000000013', $workplace, '0198f8c0-0000-7000-8000-000000000001', null, $unit->id(), null, null, true, $now);

        $key = (new SwapPoolResolver())->resolve($assignment);

        self::assertSame($unit->id(), $key->organizationalUnitId);
        self::assertStringContainsString($unit->id(), $key->fingerprint());
        self::assertSame(OrganizationalUnitKind::FLOATING_TEAM, $unit->kind());
    }

    public function test_workplace_identity_is_required_for_a_unit(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $first = new WorkplaceId('0198f8c0-0000-7000-8000-000000000010');
        $second = new WorkplaceId('0198f8c0-0000-7000-8000-000000000020');
        $unit = OrganizationalUnit::create('0198f8c0-0000-7000-8000-000000000011', $first, 'Correturnos', null, ['staff'], OrganizationalUnitKind::FLOATING_TEAM, OrganizationalUnitStatus::PENDING, null, $now);

        self::assertFalse($unit->workplaceId()->equals($second));
    }
}
