<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Domain\Supervision;

use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SwapPoolTeam;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupervisorVerificationPolicy::class)]
final class SupervisorVerificationPolicyTest extends TestCase
{
    #[TestWith([1, 2, false])]
    #[TestWith([2, 2, true])]
    #[TestWith([4, 2, true])]
    #[TestWith([5, 3, true])]
    #[TestWith([12, 3, true])]
    public function test_the_default_quorum_depends_on_the_team_without_the_candidate(int $colleagues, int $required, bool $reachable): void
    {
        $members = array_map(static fn (int $n): string => 'worker-'.$n, range(1, $colleagues));
        $team = new SwapPoolTeam('pool', [...$members, 'candidate']);
        $policy = new SupervisorVerificationPolicy();

        self::assertSame($required, $policy->requiredConfirmations($team, 'candidate'));
        self::assertSame($reachable, $policy->canBeReached($team, 'candidate'));
    }

    public function test_it_is_configurable(): void
    {
        $team = new SwapPoolTeam('pool', ['a', 'b', 'c', 'd']);

        self::assertSame(4, (new SupervisorVerificationPolicy(3, 4, 4))->requiredConfirmations($team, 'candidate'));
    }

    public function test_a_single_confirmation_is_never_enough(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SupervisorVerificationPolicy(1);
    }
}
