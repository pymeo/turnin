<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDayState;
use PHPUnit\Framework\TestCase;

final class RestBlockOpportunityFinderTest extends TestCase
{
    public function test_one_shift_between_two_rest_blocks_creates_five_days(): void
    {
        $opportunities = (new RestBlockOpportunityFinder())->find([$this->rest('2026-09-23'), $this->rest('2026-09-24'), $this->work('2026-09-25'), $this->rest('2026-09-26'), $this->rest('2026-09-27')]);

        self::assertCount(1, $opportunities);
        self::assertSame(5, $opportunities[0]->resultingConsecutiveRestDays);
        self::assertSame('2026-09-23', $opportunities[0]->restStartsAt);
        self::assertSame('2026-09-27', $opportunities[0]->restEndsAt);
        self::assertContains('une dos bloques de descanso', $opportunities[0]->reasons);
    }

    public function test_two_days_off_is_not_noise_worthy(): void
    {
        self::assertSame([], (new RestBlockOpportunityFinder())->find([$this->work('2026-09-25'), $this->rest('2026-09-26')]));
    }

    public function test_unknown_days_are_never_assumed_to_be_rest(): void
    {
        self::assertSame([], (new RestBlockOpportunityFinder())->find([$this->work('2026-09-25'), RosteredDay::unknown('assignment', '2026-09-26'), $this->rest('2026-09-27')]));
    }

    public function test_custom_overnight_shift_is_still_a_real_bridge_candidate(): void
    {
        $work = new RosteredDay('assignment', '2026-09-25', RosteredDayState::WORKING, 'custom', 'Guardia personalizada', 'GP', '19:00', '07:00', true);
        $result = (new RestBlockOpportunityFinder())->find([$this->rest('2026-09-24'), $work, $this->rest('2026-09-26')]);

        self::assertSame(720, $result[0]->shiftToRelease->durationMinutes());
        self::assertSame(3, $result[0]->resultingConsecutiveRestDays);
    }

    private function rest(string $date): RosteredDay
    {
        return new RosteredDay('assignment', $date, RosteredDayState::REST);
    }

    private function work(string $date): RosteredDay
    {
        return new RosteredDay('assignment', $date, RosteredDayState::WORKING, 'day-'.$date, 'Turno', 'T', '08:00', '20:00');
    }
}
