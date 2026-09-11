<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\Swap\Application\Command\DeclareAvailability;
use App\Swap\Application\Command\DeclareAvailabilityHandler;
use App\Swap\Application\Query\GetMyAvailability;
use App\Swap\Application\Query\GetMyAvailabilityHandler;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\SwapGroup;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\InMemoryAvailabilities;
use App\Tests\Support\Swap\InMemorySwapRequests;
use App\Tests\Support\Swap\SequentialSwapIds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GetMyAvailabilityHandlerTest extends TestCase
{
    public function test_five_pools_on_one_day_are_one_day_with_five_destinations(): void
    {
        $groups = [];
        foreach (['Urgencias', 'UCI', 'Consultas', 'Equipo volante', 'Hospitalización'] as $index => $destination) {
            $groups[] = new SwapGroup('pool-'.($index + 1), 'assignment', 'Centro de Salud Huéscar', $destination, 'Enfermería', 0 === $index);
        }
        $groupPort = new FixedSwapGroups(['maria' => $groups]);
        $clock = new MockClock('2026-09-15T10:00:00+00:00');
        $workspace = new SwapWorkspace($groupPort, new InMemorySwapRequests(), $clock);
        $availabilities = new InMemoryAvailabilities();
        $declare = new DeclareAvailabilityHandler(
            $workspace,
            $availabilities,
            new FixedRosteredDays(),
            new SequentialSwapIds(),
            $clock,
        );

        $declare(new DeclareAvailability(
            'maria',
            'assignment',
            '2026-09-19',
            array_map(static fn (SwapGroup $group): string => $group->poolId, $groups),
            ['morning', 'evening'],
        ));

        $view = (new GetMyAvailabilityHandler($workspace, $availabilities))(new GetMyAvailability('maria'));

        self::assertCount(1, $view, 'Presentation is grouped by date, never by storage row.');
        self::assertSame(['morning', 'evening'], $view[0]->shiftKinds);
        self::assertCount(5, $view[0]->poolIds);
        self::assertSame(['Urgencias', 'UCI', 'Consultas', 'Equipo volante', 'Hospitalización'], $view[0]->places[0]->destinations);
        self::assertSame(10, $availabilities->count(), 'The valid pool × shift slots remain distinct internally.');
    }
}
