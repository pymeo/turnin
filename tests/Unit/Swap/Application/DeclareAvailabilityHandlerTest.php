<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\Swap\Application\Command\DeclareAvailability;
use App\Swap\Application\Command\DeclareAvailabilityHandler;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\WorkDate;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\InMemoryAvailabilities;
use App\Tests\Support\Swap\InMemorySwapRequests;
use App\Tests\Support\Swap\SequentialSwapIds;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "Puedo trabajar este día.".
 *
 * An explicit statement. The two rules that matter: it is never derived from an
 * empty calendar, and declaring it never writes one.
 */
final class DeclareAvailabilityHandlerTest extends TestCase
{
    private const DATE = '2026-09-21';

    private InMemoryAvailabilities $availabilities;

    protected function setUp(): void
    {
        $this->availabilities = new InMemoryAvailabilities();
    }

    /** With one destination there is nothing to ask: it goes to that group. */
    public function test_it_declares_in_every_group_the_calendar_reaches(): void
    {
        $pools = ($this->handler())(new DeclareAvailability('maria', 'assignment-2', self::DATE, [], ['morning']));

        self::assertSame(['pool-uci', 'pool-emergency'], $pools);
        self::assertSame(2, $this->availabilities->count());
    }

    public function test_it_can_be_narrowed_to_the_chosen_groups(): void
    {
        $pools = ($this->handler())(new DeclareAvailability('maria', 'assignment-2', self::DATE, ['pool-uci'], ['morning']));

        self::assertSame(['pool-uci'], $pools);
        self::assertSame(1, $this->availabilities->count());
    }

    /**
     * A day with no roster entry is exactly when somebody wants to offer: the
     * statement is the information, and it must not become a rest day.
     */
    public function test_a_day_with_no_information_can_be_offered(): void
    {
        ($this->handler(new FixedRosteredDays()))(new DeclareAvailability('maria', 'assignment-2', self::DATE, [], ['morning']));

        self::assertSame(2, $this->availabilities->count());
    }

    public function test_a_rest_day_can_be_offered(): void
    {
        ($this->handler(FixedRosteredDays::rest('assignment-2', self::DATE)))(new DeclareAvailability('maria', 'assignment-2', self::DATE, [], ['morning']));

        self::assertSame(2, $this->availabilities->count());
    }

    public function test_somebody_already_working_that_day_cannot_also_cover_a_shift(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ya trabajas');

        ($this->handler(FixedRosteredDays::working('assignment-2', self::DATE)))(new DeclareAvailability('maria', 'assignment-2', self::DATE));
    }

    public function test_repeated_requests_leave_one_effective_statement(): void
    {
        $handler = $this->handler();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $handler(new DeclareAvailability('maria', 'assignment-2', self::DATE, ['pool-uci'], ['morning']));
        }

        self::assertSame(1, $this->availabilities->count());
        self::assertTrue($this->availabilities->forSlot('maria', 'pool-uci', WorkDate::fromString(self::DATE), \App\SharedKernel\Domain\ShiftKind::MORNING)?->isActive());
    }

    public function test_a_pool_the_worker_does_not_belong_to_is_refused(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new DeclareAvailability('maria', 'assignment-2', self::DATE, ['pool-of-a-stranger']));
    }

    public function test_a_calendar_that_is_not_mine_is_refused(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new DeclareAvailability('maria', 'assignment-of-pedro', self::DATE));
    }

    private function handler(?RosteredDays $days = null): DeclareAvailabilityHandler
    {
        $groups = new FixedSwapGroups(['maria' => [
            new SwapGroup('pool-uci', 'assignment-2', 'Virgen de las Nieves', 'UCI', 'Enfermería', true),
            new SwapGroup('pool-emergency', 'assignment-2', 'Virgen de las Nieves', 'Urgencias', 'Enfermería', false),
        ]]);
        $clock = new MockClock('2026-09-15T10:00:00+00:00');

        return new DeclareAvailabilityHandler(
            new SwapWorkspace($groups, new InMemorySwapRequests(), $clock),
            $this->availabilities,
            $days ?? new FixedRosteredDays(),
            new SequentialSwapIds(),
            $clock,
        );
    }
}
