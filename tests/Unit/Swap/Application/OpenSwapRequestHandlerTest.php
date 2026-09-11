<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\Swap\Application\Command\OpenSwapRequest;
use App\Swap\Application\Command\OpenSwapRequestHandler;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\InMemorySwapRequests;
use App\Tests\Support\Swap\SequentialSwapIds;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "Quiero quitarme este turno.".
 *
 * Publishing has to be impossible for a shift that is not the worker's, for a
 * day they are not working, and for a group they do not belong to — and it must
 * not write anything to a calendar.
 */
final class OpenSwapRequestHandlerTest extends TestCase
{
    private const DATE = '2026-09-18';

    private InMemorySwapRequests $requests;

    protected function setUp(): void
    {
        $this->requests = new InMemorySwapRequests();
    }

    public function test_it_publishes_a_future_shift_to_the_group_of_its_calendar(): void
    {
        $id = ($this->handler())(new OpenSwapRequest('pedro', 'assignment-1', self::DATE));

        $request = $this->requests->byId($id);
        self::assertNotNull($request);
        self::assertSame('pool-uci', $request->swapPoolId());
        self::assertSame('roster-day', $request->rosterDayId());
        self::assertTrue($request->isOpen());
    }

    /** A double tap on a phone is one request, not two. */
    public function test_publishing_the_same_shift_twice_returns_the_same_request(): void
    {
        $handler = $this->handler();

        $first = $handler(new OpenSwapRequest('pedro', 'assignment-1', self::DATE));
        $second = $handler(new OpenSwapRequest('pedro', 'assignment-1', self::DATE));

        self::assertSame($first, $second);
        self::assertSame(1, $this->requests->count());
    }

    public function test_a_rest_day_cannot_be_published(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('un día en el que trabajas');

        ($this->handler(FixedRosteredDays::rest('assignment-1', self::DATE)))(new OpenSwapRequest('pedro', 'assignment-1', self::DATE));
    }

    /**
     * A day nobody filled in is not a free day and not a shift. Publishing it
     * would offer something that does not exist.
     */
    public function test_a_day_with_no_information_cannot_be_published(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->handler(new FixedRosteredDays()))(new OpenSwapRequest('pedro', 'assignment-1', self::DATE));
    }

    public function test_a_calendar_that_is_not_mine_cannot_be_published_from(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new OpenSwapRequest('pedro', 'assignment-of-maria', self::DATE));
    }

    /** The pool must be reachable from that assignment, not merely one of mine. */
    public function test_a_pool_from_another_centre_cannot_be_chosen(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new OpenSwapRequest('pedro', 'assignment-1', self::DATE, 'pool-other-hospital'));
    }

    public function test_a_pool_that_is_nobody_of_mine_is_refused(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new OpenSwapRequest('pedro', 'assignment-1', self::DATE, 'pool-of-a-stranger'));
    }

    private function handler(?RosteredDays $days = null): OpenSwapRequestHandler
    {
        $groups = new FixedSwapGroups(['pedro' => [
            new SwapGroup('pool-uci', 'assignment-1', 'Virgen de las Nieves', 'UCI', 'Enfermería', true),
            new SwapGroup('pool-other-hospital', 'assignment-2', 'Hospital Clínico', 'UCI', 'Enfermería', false),
        ]]);
        $clock = new MockClock('2026-09-15T10:00:00+00:00');

        return new OpenSwapRequestHandler(
            new SwapWorkspace($groups, $this->requests, $clock),
            $this->requests,
            $days ?? FixedRosteredDays::working('assignment-1', self::DATE),
            new SequentialSwapIds(),
            $clock,
        );
    }
}
