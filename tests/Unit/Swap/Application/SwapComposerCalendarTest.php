<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Application;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\Query\GetSwapComposerCalendar;
use App\Swap\Application\Query\GetSwapComposerCalendarHandler;
use App\Swap\Application\Query\SwapComposerCalendarView;
use App\Swap\Application\Query\SwapComposerDayView;
use App\Swap\Application\Query\SwapComposerShiftView;
use App\Swap\Application\ShiftCompatibilityResolver;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\WorkDate;
use App\Tests\Support\Swap\FixedRosteredDays;
use App\Tests\Support\Swap\FixedSwapGroups;
use App\Tests\Support\Swap\FixedWorkerDisplayNames;
use App\Tests\Support\Swap\InMemorySwapRequests;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SwapComposerCalendarTest extends TestCase
{
    private const string TODAY = '2026-09-13';
    private const string REQUEST_DATE = '2026-09-26';

    private InMemorySwapRequests $requests;

    protected function setUp(): void
    {
        $this->requests = new InMemorySwapRequests([SwapRequest::open(
            'david-evening',
            'david',
            'assignment-david',
            'pool-uci',
            'roster-david-26',
            WorkDate::fromString(self::REQUEST_DATE),
            ShiftKind::EVENING,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
        )]);
    }

    public function test_calendar_is_four_weeks_and_distinguishes_work_rest_and_unknown_days(): void
    {
        $view = $this->view();

        self::assertSame('2026-09-07', $view->rangeStart);
        self::assertSame('2026-10-04', $view->rangeEnd);
        self::assertCount(4, $view->weeks);
        self::assertSame('Trabajas', $this->day($view, '2026-09-16')->stateLabel);
        self::assertSame('Libre', $this->day($view, '2026-09-18')->stateLabel);
        self::assertSame('Sin datos', $this->day($view, '2026-09-30')->stateLabel);
    }

    public function test_real_future_shift_is_selectable_and_bridge_is_a_quiet_recommendation(): void
    {
        $view = $this->view();
        $ordinary = $this->shift($view, 'assignment-ana|2026-09-16');
        $bridge = $this->shift($view, 'assignment-ana|2026-09-17');

        self::assertTrue($ordinary->selectable);
        self::assertSame('08:00–15:00', $ordinary->hours);
        self::assertSame('7 h', $ordinary->durationLabel);
        self::assertSame('Te dejaría 4 días seguidos libres', $bridge->recommendation);
        self::assertTrue($this->day($view, '2026-09-17')->isRecommended);
    }

    public function test_colleague_overlap_keeps_my_shift_visible_but_blocks_selection_with_reason(): void
    {
        $days = $this->calendar()->with([
            RosteredDay::keyFor('assignment-david', '2026-09-16') => $this->work('assignment-david', '2026-09-16', 'David trabaja', 'D', '10:00', '18:00'),
        ]);

        $shift = $this->shift($this->view($days), 'assignment-ana|2026-09-16');

        self::assertFalse($shift->selectable);
        self::assertSame('David ya trabaja ese día.', $shift->blockedReason);
    }

    public function test_same_date_is_not_a_block_when_the_real_intervals_allow_the_exchange(): void
    {
        $days = $this->calendar()->with([
            RosteredDay::keyFor('assignment-ana', self::REQUEST_DATE) => $this->work('assignment-ana', self::REQUEST_DATE, 'Mañana corta', 'MC', '07:00', '12:00'),
        ]);

        $sameDate = $this->shift($this->view($days), 'assignment-ana|'.self::REQUEST_DATE);

        self::assertTrue($sameDate->selectable);
        self::assertNull($sameDate->blockedReason);
    }

    public function test_temporal_conflict_on_the_requested_shift_blocks_the_whole_composer(): void
    {
        $days = $this->calendar()->with([
            RosteredDay::keyFor('assignment-ana-noche', self::REQUEST_DATE) => $this->work('assignment-ana-noche', self::REQUEST_DATE, 'Solape', 'S', '18:00', '23:00'),
        ]);

        self::assertSame('Trabajas de 18:00 a 23:00.', $this->view($days)->blockedReason);
    }

    public function test_overnight_intervals_are_used_instead_of_template_names(): void
    {
        $days = $this->calendar()->with([
            RosteredDay::keyFor('assignment-ana', '2026-09-23') => $this->work('assignment-ana', '2026-09-23', 'Guardia especial', 'GE', '20:00', '08:00', ShiftKind::OTHER),
            RosteredDay::keyFor('assignment-david', '2026-09-24') => $this->work('assignment-david', '2026-09-24', 'Noche', 'N', '07:00', '15:00', ShiftKind::NIGHT),
        ]);

        $shift = $this->shift($this->view($days), 'assignment-ana|2026-09-23');

        self::assertSame('12 h', $shift->durationLabel);
        self::assertTrue($shift->endsNextDay);
        self::assertFalse($shift->selectable);
        self::assertSame('David ya trabaja ese día.', $shift->blockedReason);
    }

    public function test_existing_open_request_is_not_offered_twice(): void
    {
        $this->requests->save(SwapRequest::open(
            'ana-wednesday',
            'ana',
            'assignment-ana',
            'pool-uci',
            'roster-assignment-ana-2026-09-16',
            WorkDate::fromString('2026-09-16'),
            ShiftKind::MORNING,
            WorkDate::fromString(self::TODAY),
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
        ));

        self::assertSame('Ya lo has publicado para que alguien lo cubra.', $this->shift($this->view(), 'assignment-ana|2026-09-16')->blockedReason);
    }

    public function test_multiple_selected_keys_survive_week_navigation(): void
    {
        $selected = ['assignment-ana|2026-09-16', 'assignment-ana|2026-09-17'];
        $view = ($this->handler($this->calendar()))(new GetSwapComposerCalendar('ana', 'david-evening', 1, $selected));

        self::assertSame($selected, $view->selectedKeys);
        self::assertSame(5, $view->maximumOptions);
        self::assertTrue($view->hasPreviousWeeks);
    }

    public function test_colleagues_private_calendar_is_used_for_verdicts_but_never_rendered(): void
    {
        $view = $this->view($this->calendar()->with([
            RosteredDay::keyFor('assignment-david', '2026-09-16') => $this->work('assignment-david', '2026-09-16'),
        ]));

        foreach ($view->shifts as $shift) {
            self::assertNotSame('assignment-david', $shift->assignmentId);
        }
        self::assertSame('David', $view->authorName);
    }

    public function test_only_a_recipient_can_open_the_composer_and_never_the_author(): void
    {
        foreach (['antonio', 'david'] as $workerId) {
            try {
                ($this->handler($this->calendar()))(new GetSwapComposerCalendar($workerId, 'david-evening'));
                self::fail('Access should have been refused.');
            } catch (SwapAccessDenied) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function view(?FixedRosteredDays $days = null): SwapComposerCalendarView
    {
        $calendar = $days ?? $this->calendar();

        return ($this->handler($calendar))(new GetSwapComposerCalendar('ana', 'david-evening'));
    }

    private function handler(FixedRosteredDays $days): GetSwapComposerCalendarHandler
    {
        $groups = new FixedSwapGroups([
            'david' => [new SwapGroup('pool-uci', 'assignment-david', 'Virgen de las Nieves', 'UCI', 'Enfermería', true)],
            'ana' => [
                new SwapGroup('pool-uci', 'assignment-ana', 'Virgen de las Nieves', 'UCI', 'Enfermería', true),
                new SwapGroup('pool-uci', 'assignment-ana-noche', 'Virgen de las Nieves', 'UCI', 'Enfermería', false),
            ],
            'antonio' => [new SwapGroup('pool-celadores', 'assignment-antonio', 'Virgen de las Nieves', 'Urgencias', 'Celadores', true)],
        ]);
        $clock = new MockClock('2026-09-13T10:00:00+00:00');

        return new GetSwapComposerCalendarHandler(
            new SwapWorkspace($groups, $this->requests, $clock),
            $days,
            $this->requests,
            $groups,
            new ShiftCompatibilityResolver($days, $clock),
            new RestBlockOpportunityFinder(),
            new FixedWorkerDisplayNames(['david' => 'David', 'ana' => 'Ana']),
        );
    }

    private function calendar(): FixedRosteredDays
    {
        $days = [
            RosteredDay::keyFor('assignment-david', self::REQUEST_DATE) => $this->work('assignment-david', self::REQUEST_DATE, 'Tarde', 'T', '16:00', '22:00', ShiftKind::EVENING),
            RosteredDay::keyFor('assignment-ana', '2026-09-16') => $this->work('assignment-ana', '2026-09-16'),
            RosteredDay::keyFor('assignment-ana', '2026-09-17') => $this->work('assignment-ana', '2026-09-17'),
        ];
        foreach (['2026-09-18', '2026-09-19', '2026-09-20', self::REQUEST_DATE] as $date) {
            $days[RosteredDay::keyFor('assignment-ana', $date)] = $this->rest('assignment-ana', $date);
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

        self::fail($date.' is not visible.');
    }

    private function shift(SwapComposerCalendarView $view, string $key): SwapComposerShiftView
    {
        foreach ($view->shifts as $shift) {
            if ($shift->key === $key) {
                return $shift;
            }
        }

        self::fail($key.' is not present.');
    }
}
