<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposalOption;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SwapProposalTest extends TestCase
{
    public function test_exchange_accepts_one_or_five_real_return_options(): void
    {
        $one = $this->exchange([$this->option(1)]);
        self::assertCount(1, $one->options());
        self::assertSame(SwapProposalStatus::PENDING, $one->status());

        $five = $this->exchange(array_map(fn (int $number): SwapProposalOption => $this->option($number), range(1, 5)));
        self::assertCount(5, $five->options());
    }

    public function test_exchange_rejects_zero_more_than_five_and_duplicate_options(): void
    {
        try {
            /* @phpstan-ignore argument.type */
            SwapProposal::proposeExchange('empty', 'request', 'pedro', 'ana', 'assignment-ana', [], $this->now());
            self::fail('An empty exchange should have been rejected.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        foreach ([array_map(fn (int $number): SwapProposalOption => $this->option($number), range(1, 6)), [$this->option(1), $this->option(1, 'another-id')]] as $options) {
            try {
                $this->exchange($options);
                self::fail('The invalid options should have been rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_owner_chooses_exactly_one_offered_shift(): void
    {
        $proposal = $this->exchange([$this->option(1), $this->option(2)]);
        $proposal->chooseOption('pedro', 'option-2', $this->now());

        self::assertSame('option-2', $proposal->chosenOptionId());
        self::assertSame('2026-09-22', (string) $proposal->chosenOption()?->workDate);

        $this->expectException(InvalidArgumentException::class);
        $proposal->chooseOption('pedro', 'not-offered', $this->now());
    }

    public function test_non_exchange_kinds_keep_their_existing_contract_without_return_options(): void
    {
        $coverage = SwapProposal::propose('coverage', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::COVERAGE, $this->now());
        $preference = new ReturnPreference('2026-10', null, 720, [5, 6]);
        $deferred = SwapProposal::propose('deferred', 'request', 'pedro', 'ana', 'assignment-ana', SwapProposalKind::DEFERRED, $this->now(), $preference);

        self::assertSame([], $coverage->options());
        self::assertSame($preference, $deferred->returnPreference());
    }

    public function test_owner_rejects_and_proposer_withdraws(): void
    {
        $rejected = $this->exchange([$this->option(1)]);
        $rejected->reject('pedro', $this->now());
        self::assertSame(SwapProposalStatus::REJECTED, $rejected->status());

        $withdrawn = $this->exchange([$this->option(1)]);
        $withdrawn->withdraw('ana', $this->now());
        self::assertSame(SwapProposalStatus::WITHDRAWN, $withdrawn->status());
    }

    public function test_wrong_professional_cannot_choose(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->exchange([$this->option(1)])->chooseOption('ana', 'option-1', $this->now());
    }

    /** @param non-empty-list<SwapProposalOption> $options */
    private function exchange(array $options): SwapProposal
    {
        return SwapProposal::proposeExchange('proposal', 'request', 'pedro', 'ana', 'assignment-ana', $options, $this->now());
    }

    private function option(int $number, ?string $id = null): SwapProposalOption
    {
        return new SwapProposalOption(
            $id ?? 'option-'.$number,
            'assignment-ana',
            'roster-ana-'.$number,
            WorkDate::fromString('2026-09-'.str_pad((string) (20 + $number), 2, '0', \STR_PAD_LEFT)),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15T10:00:00+00:00');
    }
}
