<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SwapProposalTest extends TestCase
{
    public function test_exchange_references_a_real_return_shift(): void
    {
        $proposal = $this->exchange();

        self::assertSame('roster-ana-22', $proposal->offeredRosterDayId());
        self::assertSame('2026-09-22', (string) $proposal->offeredWorkDate());
        self::assertSame(SwapProposalStatus::PENDING, $proposal->status());
    }

    public function test_coverage_has_no_return_shift(): void
    {
        $proposal = SwapProposal::propose('proposal', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::COVERAGE, null, null, $this->now());

        self::assertNull($proposal->offeredRosterDayId());
        self::assertNull($proposal->offeredWorkDate());
    }

    public function test_deferred_exchange_can_store_a_preference_or_leave_it_empty(): void
    {
        $preference = new ReturnPreference('2026-10', null, 720, [5, 6]);
        $withPreference = SwapProposal::propose('proposal', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::DEFERRED, null, null, $this->now(), $preference);
        $withoutPreference = SwapProposal::propose('proposal-2', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::DEFERRED, null, null, $this->now());

        self::assertSame($preference, $withPreference->returnPreference());
        self::assertNull($withoutPreference->returnPreference());
    }

    public function test_exchange_without_real_shift_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SwapProposal::propose('proposal', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::EXCHANGE, null, null, $this->now());
    }

    public function test_owner_accepts_or_rejects_and_proposer_withdraws(): void
    {
        $accepted = $this->exchange();
        $accepted->accept('pedro', $this->now());
        self::assertSame(SwapProposalStatus::ACCEPTED, $accepted->status());

        $rejected = $this->exchange();
        $rejected->reject('pedro', $this->now());
        self::assertSame(SwapProposalStatus::REJECTED, $rejected->status());

        $withdrawn = $this->exchange();
        $withdrawn->withdraw('ana', $this->now());
        self::assertSame(SwapProposalStatus::WITHDRAWN, $withdrawn->status());
    }

    public function test_wrong_professional_cannot_decide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->exchange()->accept('ana', $this->now());
    }

    private function exchange(): SwapProposal
    {
        return SwapProposal::propose('proposal', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::EXCHANGE, 'roster-ana-22', WorkDate::fromString('2026-09-22'), $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15T10:00:00+00:00');
    }
}
