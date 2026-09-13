<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequestStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SwapRequestTest extends TestCase
{
    private const TODAY = '2026-09-15';

    public function test_a_published_shift_starts_open(): void
    {
        $request = $this->open('2026-09-18');

        self::assertSame(SwapRequestStatus::OPEN, $request->status());
        self::assertTrue($request->isOpen());
        self::assertSame('2026-09-18', (string) $request->workDate());
        self::assertSame('roster-day', $request->rosterDayId(), 'The shift is referenced, never copied.');
    }

    public function test_a_shift_in_the_past_cannot_be_published(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('turnos futuros');

        $this->open('2026-09-14');
    }

    /** Today is already being worked: there is nobody left to hand it to. */
    public function test_todays_shift_cannot_be_published(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->open(self::TODAY);
    }

    public function test_withdrawing_cancels_without_deleting(): void
    {
        $request = $this->open('2026-09-18');

        $request->cancel('pedro', $this->now());

        self::assertSame(SwapRequestStatus::CANCELLED, $request->status());
        self::assertFalse($request->isOpen());
        self::assertSame('2026-09-18', (string) $request->workDate(), 'A withdrawn request is still a record of what happened.');
    }

    public function test_only_its_author_can_withdraw_it(): void
    {
        $request = $this->open('2026-09-18');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Solo quien publica');
        $request->cancel('maria', $this->now());
    }

    public function test_it_cannot_be_withdrawn_twice(): void
    {
        $request = $this->open('2026-09-18');
        $request->cancel('pedro', $this->now());

        $this->expectException(InvalidArgumentException::class);
        $request->cancel('pedro', $this->now());
    }

    public function test_it_needs_a_worker_an_assignment_a_pool_and_a_day(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SwapRequest::open('id', 'pedro', 'assignment', '', 'roster-day', WorkDate::fromString('2026-09-18'), ShiftKind::NIGHT, WorkDate::fromString(self::TODAY), $this->now());
    }

    public function test_only_the_author_can_choose_a_different_worker_to_cover_it(): void
    {
        $request = $this->open('2026-09-18');
        $request->cover('pedro', 'maria', 'assignment-maria', $this->now());

        self::assertSame(SwapRequestStatus::COVERED, $request->status());
        self::assertSame('maria', $request->coveredByWorkerId());
        self::assertSame('assignment-maria', $request->coveredByAssignmentId());
        self::assertFalse($request->isOpen());
    }

    public function test_a_covered_request_cannot_be_covered_twice(): void
    {
        $request = $this->open('2026-09-18');
        $request->cover('pedro', 'maria', 'assignment-maria', $this->now());

        $this->expectException(InvalidArgumentException::class);
        $request->cover('pedro', 'javier', 'assignment-javier', $this->now());
    }

    private function open(string $date): SwapRequest
    {
        return SwapRequest::open(
            'request-1',
            'pedro',
            'assignment-1',
            'pool-uci',
            'roster-day',
            WorkDate::fromString($date),
            ShiftKind::NIGHT,
            WorkDate::fromString(self::TODAY),
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15T10:00:00+00:00');
    }
}
