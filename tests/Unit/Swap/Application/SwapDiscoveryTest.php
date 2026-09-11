<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\Query\GetOpenSwapRequests;
use App\Swap\Application\Query\GetOpenSwapRequestsHandler;
use App\Swap\Application\Query\GetSwapRequestCandidates;
use App\Swap\Application\Query\GetSwapRequestCandidatesHandler;
use App\Swap\Application\Query\OpenSwapRequestView;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availability;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\WorkDate;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\FixedWorkerDisplayNames;
use App\Tests\Support\Swap\InMemoryAvailabilities;
use App\Tests\Support\Swap\InMemorySwapRequests;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The frontier is the swap pool, never the hospital.
 *
 * Pedro and María are both nurses in intensive care at Virgen de las Nieves.
 * Antonio is a porter in A&E at the same hospital: same building, different
 * pool, and he must not see their shifts at all.
 */
final class SwapDiscoveryTest extends TestCase
{
    private const DATE = '2026-09-18';

    private InMemorySwapRequests $requests;

    private InMemoryAvailabilities $availabilities;

    protected function setUp(): void
    {
        $this->requests = new InMemorySwapRequests([
            $this->request('pedro-night', 'pedro', 'assignment-pedro', 'pool-uci'),
            $this->request('antonio-night', 'antonio', 'assignment-antonio', 'pool-porters'),
        ]);
        $this->availabilities = new InMemoryAvailabilities();
    }

    public function test_a_colleague_in_the_same_pool_sees_the_shift(): void
    {
        $views = ($this->openRequests())(new GetOpenSwapRequests('maria'));

        self::assertCount(1, $views);
        self::assertSame('pedro-night', $views[0]->requestId);
        self::assertSame('Pedro', $views[0]->authorName);
        self::assertSame('UCI · Enfermería', $views[0]->groupLabel);
        self::assertSame('22:00–08:00', $views[0]->hours);
        self::assertSame('viernes 18 sep', $views[0]->dateHeadline);
    }

    /** Same hospital, different pool: nothing crosses. */
    public function test_a_worker_from_another_pool_sees_nothing_of_theirs(): void
    {
        $views = ($this->openRequests())(new GetOpenSwapRequests('antonio'));

        self::assertSame([], array_map(static fn (OpenSwapRequestView $view): string => $view->requestId, $views));
    }

    public function test_nobody_is_offered_their_own_shift(): void
    {
        $views = ($this->openRequests())(new GetOpenSwapRequests('pedro'));

        self::assertSame([], $views);
    }

    public function test_narrowing_by_group_can_only_ever_narrow(): void
    {
        $views = ($this->openRequests())(new GetOpenSwapRequests('maria', 'pool-uci'));
        self::assertCount(1, $views);

        // A pool María does not belong to is refused rather than answered.
        $this->expectException(SwapAccessDenied::class);
        ($this->openRequests())(new GetOpenSwapRequests('maria', 'pool-porters'));
    }

    /**
     * The author cleared the day after publishing. The request is stale, so it
     * is dropped rather than rendered against a shift that no longer exists.
     */
    public function test_a_request_whose_day_disappeared_is_not_shown(): void
    {
        $views = ($this->openRequests(new FixedRosteredDays()))(new GetOpenSwapRequests('maria'));

        self::assertSame([], $views);
    }

    public function test_a_card_says_when_the_worker_already_offered_for_that_day(): void
    {
        $this->availabilities->save(Availability::declare(
            'availability-1',
            'maria',
            'assignment-maria',
            'pool-uci',
            WorkDate::fromString(self::DATE),
            ShiftKind::NIGHT,
            WorkDate::fromString('2026-09-15'),
            new DateTimeImmutable('2026-09-15T10:00:00+00:00'),
        ));

        $views = ($this->openRequests())(new GetOpenSwapRequests('maria'));

        self::assertTrue($views[0]->alreadyAvailable);
    }

    public function test_the_author_sees_who_offered_and_never_themselves(): void
    {
        foreach (['maria', 'pedro'] as $index => $workerId) {
            $this->availabilities->save(Availability::declare(
                'availability-'.$index,
                $workerId,
                'assignment-'.$workerId,
                'pool-uci',
                WorkDate::fromString(self::DATE),
                ShiftKind::NIGHT,
                WorkDate::fromString('2026-09-15'),
                new DateTimeImmutable('2026-09-15T10:00:00+00:00'),
            ));
        }

        $candidates = ($this->candidates())(new GetSwapRequestCandidates('pedro', 'pedro-night'));

        self::assertCount(1, $candidates);
        self::assertSame('María', $candidates[0]->name);
        self::assertSame('UCI · Enfermería', $candidates[0]->groupLabel);
    }

    /** A colleague can see the shift; the queue of people interested is private. */
    public function test_only_the_author_can_see_the_candidates(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->candidates())(new GetSwapRequestCandidates('maria', 'pedro-night'));
    }

    private function openRequests(?FixedRosteredDays $days = null): GetOpenSwapRequestsHandler
    {
        return new GetOpenSwapRequestsHandler(
            $this->workspace(),
            $this->requests,
            $days ?? $this->rosteredDays(),
            $this->availabilities,
            $this->names(),
        );
    }

    private function candidates(): GetSwapRequestCandidatesHandler
    {
        return new GetSwapRequestCandidatesHandler($this->workspace(), $this->availabilities, $this->names());
    }

    private function workspace(): SwapWorkspace
    {
        $groups = new FixedSwapGroups([
            'pedro' => [new SwapGroup('pool-uci', 'assignment-pedro', 'Virgen de las Nieves', 'UCI', 'Enfermería', true)],
            'maria' => [new SwapGroup('pool-uci', 'assignment-maria', 'Virgen de las Nieves', 'UCI', 'Enfermería', true)],
            'antonio' => [new SwapGroup('pool-porters', 'assignment-antonio', 'Virgen de las Nieves', 'Urgencias', 'Celadores', true)],
        ]);

        return new SwapWorkspace($groups, $this->requests, new MockClock('2026-09-15T10:00:00+00:00'));
    }

    private function rosteredDays(): FixedRosteredDays
    {
        $night = static fn (string $assignmentId): RosteredDay => new RosteredDay(
            $assignmentId,
            self::DATE,
            RosteredDayState::WORKING,
            'roster-'.$assignmentId,
            'Noche',
            'N',
            '22:00',
            '08:00',
            true,
            'blue',
        );

        return new FixedRosteredDays([
            RosteredDay::keyFor('assignment-pedro', self::DATE) => $night('assignment-pedro'),
            RosteredDay::keyFor('assignment-antonio', self::DATE) => $night('assignment-antonio'),
        ]);
    }

    private function names(): FixedWorkerDisplayNames
    {
        return new FixedWorkerDisplayNames(['pedro' => 'Pedro', 'maria' => 'María', 'antonio' => 'Antonio']);
    }

    private function request(string $id, string $workerId, string $assignmentId, string $poolId): SwapRequest
    {
        return SwapRequest::open(
            $id,
            $workerId,
            $assignmentId,
            $poolId,
            'roster-'.$assignmentId,
            WorkDate::fromString(self::DATE),
            ShiftKind::NIGHT,
            WorkDate::fromString('2026-09-15'),
            new DateTimeImmutable('2026-09-15T10:00:00+00:00'),
        );
    }
}
