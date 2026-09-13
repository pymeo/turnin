<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\Query\GetSwapComposerCalendar;
use App\Swap\Application\Query\GetSwapComposerCalendarHandler;
use App\Swap\Application\Query\SwapComposerCalendarView;
use App\Swap\Application\Query\SwapComposerDayView;
use App\Swap\Application\Query\SwapComposerShiftView;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\WorkDate;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\FixedWorkerDisplayNames;
use App\Tests\Support\Swap\InMemoryExchangeBalances;
use App\Tests\Support\Swap\InMemorySwapRequests;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Ana tapped "Me interesa" on David's Saturday guardia and now has to pick one
 * of her own shifts to ask for in return.
 *
 * Every assertion here is about the thing the old list of cards could not say:
 * which days around a shift she works, which she has off, which of hers are
 * actually offerable, and what giving one away would buy her.
 *
 * Today is Sunday 13 September 2026, so the window runs from Monday the 7th.
 */
final class SwapComposerCalendarTest extends TestCase
{
    private const TODAY = '2026-09-13';

    private const REQUEST_DATE = '2026-09-26';

    private InMemorySwapRequests $requests;

    private InMemoryExchangeBalances $balances;

    protected function setUp(): void
    {
        $this->requests = new InMemorySwapRequests([SwapRequest::open(
            'david-guardia',
            'david',
            'assignment-david',
            'pool-uci',
            'roster-david-26',
            WorkDate::fromString(self::REQUEST_DATE),
            ShiftKind::ON_CALL,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
        )]);
        $this->balances = new InMemoryExchangeBalances();
    }

    public function test_the_window_is_four_weeks_from_the_monday_of_this_week(): void
    {
        $view = $this->view();

        self::assertSame('2026-09-07', $view->rangeStart);
        self::assertSame('2026-10-04', $view->rangeEnd);
        self::assertCount(4, $view->weeks);
        self::assertCount(7, $view->weeks[0]->days);
        self::assertSame('2026-09-07', $view->weeks[0]->days[0]->date);
        self::assertSame('7 sep – 13 sep', $view->weeks[0]->label);
        self::assertFalse($view->hasPreviousWeeks);
        self::assertTrue($view->hasMoreWeeks);
    }

    /** The whole reason this screen is a calendar: a day off is not a blank. */
    public function test_a_rest_day_says_libre_and_a_day_with_no_data_never_does(): void
    {
        $view = $this->view();

        $friday = $this->day($view, '2026-09-18');
        self::assertTrue($friday->isRestDay);
        self::assertFalse($friday->isUnknown);
        self::assertSame('Libre', $friday->stateLabel);

        $nothingKnown = $this->day($view, '2026-09-30');
        self::assertTrue($nothingKnown->isUnknown);
        self::assertFalse($nothingKnown->isRestDay);
        self::assertSame('Sin datos', $nothingKnown->stateLabel);
        self::assertStringContainsString('sin datos en tu cuadrante', $nothingKnown->ariaLabel);
    }

    public function test_a_worked_day_carries_its_shift_and_can_be_offered(): void
    {
        $view = $this->view();
        $wednesday = $this->day($view, '2026-09-16');

        self::assertSame('Trabajas', $wednesday->stateLabel);
        self::assertCount(1, $wednesday->shifts);
        self::assertSame('08:00–15:00', $wednesday->shifts[0]->hours);
        self::assertSame('7 h', $wednesday->shifts[0]->durationLabel);
        self::assertTrue($wednesday->isSelectable);
        self::assertSame('assignment-ana|2026-09-16', $wednesday->selectableKey);
    }

    /**
     * Hiding a shift she cannot offer would take away the only thing the day
     * cell is for — whether she works it.
     */
    public function test_shifts_that_cannot_be_offered_stay_on_the_calendar_with_a_reason(): void
    {
        $this->requests->save(SwapRequest::open(
            'ana-wednesday',
            'ana',
            'assignment-ana',
            'pool-uci',
            'roster-ana-16',
            WorkDate::fromString('2026-09-16'),
            ShiftKind::MORNING,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
        ));
        $view = $this->view();

        $published = $this->shift($view, 'assignment-ana|2026-09-16');
        self::assertFalse($published->selectable);
        self::assertSame('Ya lo has publicado para que alguien lo cubra.', $published->blockedReason);
        self::assertNotSame([], $this->day($view, '2026-09-16')->shifts);

        $past = $this->shift($view, 'assignment-ana|2026-09-10');
        self::assertFalse($past->selectable);
        self::assertSame('Solo puedes ofrecer turnos futuros.', $past->blockedReason);

        $otherPool = $this->shift($view, 'assignment-ana-urgencias|2026-09-23');
        self::assertFalse($otherPool->selectable);
        self::assertSame('Este turno es de otro grupo y no entra en este cambio.', $otherPool->blockedReason);
        self::assertNull($otherPool->opportunity, 'A shift that cannot be offered is never advertised as an opportunity.');
    }

    /**
     * Ana works Monday to Thursday and has Friday, Saturday and Sunday off. If
     * David takes the Thursday she gets four days in a row, and the screen has
     * to say so without her reading a paragraph.
     */
    public function test_a_shift_that_joins_a_rest_block_is_recommended_with_its_timeline(): void
    {
        $view = $this->view();

        self::assertNotSame([], $view->recommendations);
        $best = $view->recommendations[0];
        self::assertSame('assignment-ana|2026-09-17', $best->shift->key);
        self::assertSame(4, $best->opportunity->resultingRestDays);
        self::assertSame('2026-09-17', $best->opportunity->restStartsAt);
        self::assertSame('2026-09-20', $best->opportunity->restEndsAt);
        self::assertSame('jue 17 → dom 20', $best->opportunity->restRangeLabel);
        self::assertSame('Conseguirías 4 días seguidos libres', $best->headline);

        // One day of context either side of the block, so "before" and "after"
        // can be drawn over the same stretch of calendar.
        self::assertSame(
            ['2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20', '2026-09-21'],
            array_map(static fn (SwapComposerDayView $day): string => $day->date, $best->timeline),
        );

        self::assertSame('4 días', $this->day($view, '2026-09-17')->opportunity?->badgeLabel);
        self::assertNull($this->day($view, '2026-09-15')->opportunity, 'A shift with work either side buys nothing.');
    }

    public function test_at_most_two_suggestions_and_never_the_same_rest_block_twice(): void
    {
        $view = $this->view();

        self::assertLessThanOrEqual(2, \count($view->recommendations));
        $blocks = array_map(static fn ($option): string => $option->opportunity->restStartsAt, $view->recommendations);
        self::assertSame($blocks, array_unique($blocks));
    }

    /** Three identical mid-week shifts and nothing around them: no stars. */
    public function test_nothing_is_recommended_when_no_shift_creates_a_rest_block(): void
    {
        $view = $this->view($this->flatWeek());

        self::assertSame([], $view->recommendations);
        foreach ($view->shifts as $shift) {
            self::assertNull($shift->opportunity);
        }
        self::assertCount(3, $view->shifts);
    }

    public function test_an_overnight_shift_keeps_its_real_duration(): void
    {
        $view = $this->view($this->calendar()->with([
            RosteredDay::keyFor('assignment-ana', '2026-09-23') => $this->work('assignment-ana', '2026-09-23', 'Noche', 'N', '22:00', '08:00', ShiftKind::NIGHT),
        ]));

        $night = $this->shift($view, 'assignment-ana|2026-09-23');
        self::assertSame('22:00–08:00', $night->hours);
        self::assertSame('10 h', $night->durationLabel);
        self::assertTrue($night->endsNextDay);
        self::assertSame(600, $night->durationMinutes);
    }

    /** 08:00 to 08:00 is twenty-four hours, and must never read as zero. */
    public function test_a_twenty_four_hour_guardia_is_a_full_day(): void
    {
        $view = $this->view();

        self::assertSame('08:00–08:00', $view->requestedShift->hours);
        self::assertSame('24 h', $view->requestedShift->durationLabel);
        self::assertSame(1440, $view->requestedShift->durationMinutes);
        self::assertSame('sábado 26 sep', $view->requestedShift->dateHeadline);
    }

    public function test_the_balance_is_written_from_the_proposers_side(): void
    {
        $view = $this->view();
        $wednesday = $this->shift($view, 'assignment-ana|2026-09-16');

        self::assertSame(1020, $wednesday->balanceMinutes);
        self::assertSame('+17 h', $wednesday->balanceLabel);
        self::assertSame('Trabajarías 17 h más', $wednesday->balanceHint);
    }

    /** A day can hold more than one shift, and the cell has to survive it. */
    public function test_two_shifts_on_the_same_day_are_both_offered(): void
    {
        $view = $this->view($this->calendar()->with([
            RosteredDay::keyFor('assignment-ana-noche', '2026-09-22') => $this->work('assignment-ana-noche', '2026-09-22', 'Noche', 'N', '22:00', '08:00', ShiftKind::NIGHT),
            RosteredDay::keyFor('assignment-ana', '2026-09-22') => $this->work('assignment-ana', '2026-09-22'),
        ]));

        $day = $this->day($view, '2026-09-22');
        self::assertCount(2, $day->shifts);
        self::assertTrue($day->isSelectable);
        self::assertNull($day->selectableKey, 'With two offerable shifts the cell has to ask which one.');
    }

    public function test_paging_moves_one_week_and_stops_where_shifts_stop_being_offerable(): void
    {
        $next = $this->view(null, 1);
        self::assertSame('2026-09-14', $next->rangeStart);
        self::assertSame('2026-10-11', $next->rangeEnd);
        self::assertTrue($next->hasPreviousWeeks);

        $last = $this->view(null, 999);
        self::assertSame(14, $last->weekOffset);
        self::assertSame('2026-12-14', $last->rangeStart);
        self::assertFalse($last->hasMoreWeeks);
    }

    /** A choice made three weeks ago still has to travel with the form. */
    public function test_a_selection_outside_the_visible_weeks_is_still_resolved(): void
    {
        $view = $this->view(null, 3, 'assignment-ana|2026-09-16');

        self::assertNotNull($view->selectedShift);
        self::assertSame('assignment-ana|2026-09-16', $view->selectedShift->key);
        self::assertSame('+17 h', $view->selectedShift->balanceLabel);
    }

    public function test_a_forged_selection_resolves_to_nothing(): void
    {
        foreach (['assignment-david|2026-09-16', 'nonsense', 'assignment-ana|no-es-fecha', 'assignment-ana|2026-09-30'] as $key) {
            self::assertNull($this->view(null, 0, $key)->selectedShift, $key);
        }
    }

    public function test_the_screen_says_when_she_already_works_the_day_she_would_cover(): void
    {
        $view = $this->view($this->calendar()->with([
            RosteredDay::keyFor('assignment-ana', self::REQUEST_DATE) => $this->work('assignment-ana', self::REQUEST_DATE),
        ]));

        self::assertSame('Ya trabajas ese día, así que de momento no puedes coger este turno.', $view->blockedReason);
    }

    /** This is Ana's calendar. David's other days are none of her business. */
    public function test_the_colleagues_own_calendar_never_appears(): void
    {
        $view = $this->view();

        foreach ($view->weeks as $week) {
            foreach ($week->days as $day) {
                foreach ($day->shifts as $shift) {
                    self::assertNotSame('assignment-david', $shift->assignmentId);
                }
            }
        }
        self::assertSame('David', $view->authorName);
    }

    /**
     * The order the product asks for: the rest block first, then what the
     * colleague said they wanted back. David is owed a shift and asked for a
     * night, so Ana's isolated night beats her other ordinary mornings — but the
     * Thursday that buys her four days off still comes first of all.
     */
    public function test_the_ranking_puts_rest_first_and_the_colleagues_wish_second(): void
    {
        $this->balances->save(ExchangeBalance::earn(
            'balance-1',
            'david',
            'ana',
            'some-older-request',
            'roster-david-old',
            720,
            new ReturnPreference(null, ShiftKind::NIGHT, 600),
            new DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        ));
        $view = $this->view($this->calendar()->with([
            RosteredDay::keyFor('assignment-ana', '2026-09-23') => $this->work('assignment-ana', '2026-09-23', 'Noche', 'N', '22:00', '08:00', ShiftKind::NIGHT),
        ]));

        $night = $this->shift($view, 'assignment-ana|2026-09-23');
        $ordinaryTuesday = $this->shift($view, 'assignment-ana|2026-09-15');
        $bridge = $this->shift($view, 'assignment-ana|2026-09-17');

        self::assertGreaterThan($ordinaryTuesday->score, $night->score, 'The shift he asked for beats an ordinary one.');
        self::assertGreaterThan($night->score, $bridge->score, 'A rest block beats a preference.');
        self::assertSame('assignment-ana|2026-09-17', $view->recommendations[0]->shift->key);
    }

    public function test_only_somebody_the_request_was_published_to_can_open_it(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new GetSwapComposerCalendar('antonio', 'david-guardia'));
    }

    public function test_nobody_composes_an_exchange_against_their_own_shift(): void
    {
        $this->expectException(SwapAccessDenied::class);

        ($this->handler())(new GetSwapComposerCalendar('david', 'david-guardia'));
    }

    private function view(?FixedRosteredDays $days = null, int $weekOffset = 0, ?string $selected = null): SwapComposerCalendarView
    {
        return ($this->handler($days))(new GetSwapComposerCalendar('ana', 'david-guardia', $weekOffset, $selected));
    }

    private function handler(?FixedRosteredDays $days = null): GetSwapComposerCalendarHandler
    {
        $groups = new FixedSwapGroups([
            'david' => [new SwapGroup('pool-uci', 'assignment-david', 'Virgen de las Nieves', 'UCI', 'Enfermería', true)],
            'ana' => [
                new SwapGroup('pool-uci', 'assignment-ana', 'Virgen de las Nieves', 'UCI', 'Enfermería', true),
                new SwapGroup('pool-uci', 'assignment-ana-noche', 'Virgen de las Nieves', 'UCI', 'Enfermería', false),
                new SwapGroup('pool-urgencias', 'assignment-ana-urgencias', 'Virgen de las Nieves', 'Urgencias', 'Enfermería', false),
            ],
            'antonio' => [new SwapGroup('pool-celadores', 'assignment-antonio', 'Virgen de las Nieves', 'Urgencias', 'Celadores', true)],
        ]);

        return new GetSwapComposerCalendarHandler(
            new SwapWorkspace($groups, $this->requests, new MockClock('2026-09-13T10:00:00+00:00')),
            $days ?? $this->calendar(),
            $this->requests,
            new RestBlockOpportunityFinder(),
            $this->balances,
            new FixedWorkerDisplayNames(['david' => 'David', 'ana' => 'Ana']),
        );
    }

    /**
     * Ana works Monday to Thursday, is off Friday to Sunday, and works the
     * Monday and Tuesday after. David's guardia is the Saturday she has free.
     */
    private function calendar(): FixedRosteredDays
    {
        $days = [
            RosteredDay::keyFor('assignment-david', self::REQUEST_DATE) => $this->work('assignment-david', self::REQUEST_DATE, 'Guardia', 'G', '08:00', '08:00', ShiftKind::ON_CALL),
            // David's own rota beyond the published shift, which Ana must never see.
            RosteredDay::keyFor('assignment-david', '2026-09-16') => $this->work('assignment-david', '2026-09-16'),
            RosteredDay::keyFor('assignment-ana', '2026-09-10') => $this->work('assignment-ana', '2026-09-10'),
            RosteredDay::keyFor('assignment-ana-urgencias', '2026-09-23') => $this->work('assignment-ana-urgencias', '2026-09-23', 'Tarde', 'T', '15:00', '22:00', ShiftKind::EVENING),
            RosteredDay::keyFor('assignment-ana', self::REQUEST_DATE) => $this->rest('assignment-ana', self::REQUEST_DATE),
        ];
        foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-21', '2026-09-22'] as $date) {
            $days[RosteredDay::keyFor('assignment-ana', $date)] = $this->work('assignment-ana', $date);
        }
        foreach (['2026-09-18', '2026-09-19', '2026-09-20'] as $date) {
            $days[RosteredDay::keyFor('assignment-ana', $date)] = $this->rest('assignment-ana', $date);
        }

        return new FixedRosteredDays($days);
    }

    /** Three identical shifts in the middle of a week nobody knows about. */
    private function flatWeek(): FixedRosteredDays
    {
        $days = [
            RosteredDay::keyFor('assignment-david', self::REQUEST_DATE) => $this->work('assignment-david', self::REQUEST_DATE, 'Guardia', 'G', '08:00', '08:00', ShiftKind::ON_CALL),
        ];
        foreach (['2026-09-15', '2026-09-16', '2026-09-17'] as $date) {
            $days[RosteredDay::keyFor('assignment-ana', $date)] = $this->work('assignment-ana', $date);
        }

        return new FixedRosteredDays($days);
    }

    private function work(string $assignmentId, string $date, string $label = 'Mañana', string $abbreviation = 'M', string $start = '08:00', string $end = '15:00', ShiftKind $kind = ShiftKind::MORNING): RosteredDay
    {
        return new RosteredDay($assignmentId, $date, RosteredDayState::WORKING, 'roster-'.$assignmentId.'-'.$date, $label, $abbreviation, $start, $end, $end <= $start, 'amber', $kind);
    }

    private function rest(string $assignmentId, string $date): RosteredDay
    {
        return new RosteredDay($assignmentId, $date, RosteredDayState::REST);
    }

    private function day(SwapComposerCalendarView $view, string $date): SwapComposerDayView
    {
        foreach ($view->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day->date === $date) {
                    return $day;
                }
            }
        }

        self::fail($date.' is not in the visible window.');
    }

    private function shift(SwapComposerCalendarView $view, string $key): SwapComposerShiftView
    {
        foreach ($view->shifts as $shift) {
            if ($shift->key === $key) {
                return $shift;
            }
        }

        self::fail($key.' is not among the shifts on the screen.');
    }
}
