<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Domain\Supervision;

use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignmentStatus;
use App\Workforce\Domain\Supervision\SupervisorVerification;
use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;
use App\Workforce\Domain\Supervision\SupervisorVerificationLevel;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SupervisorVerificationSource;
use App\Workforce\Domain\Supervision\SwapPoolTeam;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupervisorAssignment::class)]
final class SupervisorAssignmentTest extends TestCase
{
    private const string POOL = 'pool-uci';
    private const string LAURA = 'laura';

    private int $sequence = 0;

    public function test_accepting_an_invitation_creates_a_pending_assignment_without_authority(): void
    {
        $assignment = $this->pending();

        self::assertSame(SupervisorAssignmentStatus::PENDING_VERIFICATION, $assignment->status());
        self::assertFalse($assignment->hasApprovalAuthority());
        self::assertSame(0, $assignment->confirmations());
        self::assertNull($assignment->verifiedAt());
    }

    public function test_the_verifier_must_belong_to_the_pool(): void
    {
        $assignment = $this->pending();

        $this->expectException(SupervisionRejected::class);
        $this->expectExceptionMessage('No perteneces a este equipo');
        $assignment->recordVerification($this->confirmation('outsider'), $this->team('ana', 'david'), new SupervisorVerificationPolicy());
    }

    public function test_the_candidate_cannot_verify_herself_even_as_a_member(): void
    {
        $assignment = $this->pending();

        $this->expectException(SupervisionRejected::class);
        $assignment->recordVerification($this->confirmation(self::LAURA), $this->team(self::LAURA, 'ana', 'david'), new SupervisorVerificationPolicy());
    }

    public function test_the_same_worker_cannot_confirm_twice(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david', 'eva');

        self::assertFalse($assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy()));
        self::assertFalse($assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy()));

        self::assertSame(1, $assignment->confirmations());
        self::assertCount(1, $assignment->verifications());
        self::assertSame(SupervisorAssignmentStatus::PENDING_VERIFICATION, $assignment->status());
    }

    public function test_the_inviter_confirmation_is_recorded_once_and_explicitly(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david', 'eva');

        $assignment->recordVerification($this->confirmation('ana', SupervisorVerificationSource::INVITATION), $team, new SupervisorVerificationPolicy());
        $assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy());

        self::assertSame(1, $assignment->confirmations());
        self::assertSame(SupervisorVerificationSource::INVITATION, $assignment->verifications()[0]->source);
    }

    public function test_two_confirmations_reach_quorum_in_a_small_team(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david', 'eva', 'fran');

        self::assertFalse($assignment->recordVerification($this->confirmation('ana', SupervisorVerificationSource::INVITATION), $team, new SupervisorVerificationPolicy()));
        self::assertTrue($assignment->recordVerification($this->confirmation('david'), $team, new SupervisorVerificationPolicy()));

        self::assertSame(SupervisorAssignmentStatus::VERIFIED, $assignment->status());
        self::assertSame(SupervisorVerificationLevel::TEAM_VERIFIED, $assignment->verificationLevel());
        self::assertTrue($assignment->hasApprovalAuthority());
        self::assertNotNull($assignment->verifiedAt());
    }

    public function test_a_larger_team_needs_three_confirmations(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david', 'eva', 'fran', 'gema');

        self::assertFalse($assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy()));
        self::assertFalse($assignment->recordVerification($this->confirmation('david'), $team, new SupervisorVerificationPolicy()));
        self::assertSame(SupervisorAssignmentStatus::PENDING_VERIFICATION, $assignment->status(), 'Below quorum it stays pending.');
        self::assertTrue($assignment->recordVerification($this->confirmation('eva'), $team, new SupervisorVerificationPolicy()));

        self::assertTrue($assignment->hasApprovalAuthority());
    }

    public function test_cannot_confirm_never_counts_but_can_become_a_confirmation(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david');

        $assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy());
        self::assertFalse($assignment->recordVerification($this->answer('david', SupervisorVerificationDecision::CANNOT_CONFIRM), $team, new SupervisorVerificationPolicy()));
        self::assertSame(1, $assignment->confirmations());
        self::assertTrue($assignment->hasAnswered('david'));

        self::assertTrue($assignment->recordVerification($this->confirmation('david'), $team, new SupervisorVerificationPolicy()));
        self::assertCount(2, $assignment->verifications());
    }

    public function test_reaching_quorum_transitions_exactly_once(): void
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david', 'eva');
        $transitions = 0;

        foreach (['ana', 'david', 'eva', 'david'] as $worker) {
            $transitions += $assignment->recordVerification($this->confirmation($worker), $team, new SupervisorVerificationPolicy()) ? 1 : 0;
        }

        self::assertSame(1, $transitions);
        self::assertSame(3, $assignment->confirmations(), 'A late confirmation is still recorded for the audit trail.');
    }

    public function test_a_team_too_small_for_quorum_keeps_the_assignment_pending(): void
    {
        $assignment = $this->pending();
        $team = $this->team(self::LAURA, 'ana');

        self::assertFalse($assignment->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy()));
        self::assertSame(SupervisorAssignmentStatus::PENDING_VERIFICATION, $assignment->status());
    }

    public function test_the_team_of_another_pool_is_never_accepted(): void
    {
        $assignment = $this->pending();

        $this->expectException(InvalidArgumentException::class);
        $assignment->recordVerification($this->confirmation('ana'), new SwapPoolTeam('pool-urgencias', ['ana']), new SupervisorVerificationPolicy());
    }

    public function test_a_supervisor_can_leave_and_loses_authority_but_the_history_stays(): void
    {
        $assignment = $this->verified();
        $verifiedAt = $assignment->verifiedAt();
        $leftAt = new DateTimeImmutable('2026-10-01T09:00:00+00:00');

        self::assertTrue($assignment->leave(self::LAURA, $leftAt));

        self::assertSame(SupervisorAssignmentStatus::LEFT, $assignment->status());
        self::assertFalse($assignment->hasApprovalAuthority());
        self::assertEquals($leftAt, $assignment->leftAt());
        self::assertEquals($verifiedAt, $assignment->verifiedAt(), 'When somebody was responsible is kept.');
        self::assertCount(2, $assignment->verifications());
        self::assertFalse($assignment->leave(self::LAURA, $leftAt), 'Leaving twice is harmless.');
    }

    public function test_a_pending_supervisor_can_leave_and_the_request_stops_accepting_confirmations(): void
    {
        $assignment = $this->pending();
        $assignment->leave(self::LAURA, new DateTimeImmutable('2026-10-01T09:00:00+00:00'));

        $this->expectException(SupervisionRejected::class);
        $this->expectExceptionMessage('ya no está activa');
        $assignment->recordVerification($this->confirmation('ana'), $this->team('ana', 'david'), new SupervisorVerificationPolicy());
    }

    public function test_only_the_supervisor_can_step_down(): void
    {
        $this->expectException(SupervisionRejected::class);
        $this->verified()->leave('ana', new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    }

    public function test_one_person_can_hold_independent_assignments_and_a_pool_can_have_several_supervisors(): void
    {
        $uci = $this->verified();
        $urgencias = SupervisorAssignment::requestVerification('a-urg', self::LAURA, 'pool-urgencias', null, str_repeat('b', 64), new DateTimeImmutable('2026-09-25T10:00:00+00:00'));
        $second = SupervisorAssignment::requestVerification('a-uci-2', 'mario', self::POOL, null, str_repeat('c', 64), new DateTimeImmutable('2026-09-25T10:00:00+00:00'));
        $team = $this->team('ana', 'david');
        $second->recordVerification($this->confirmation('ana'), $team, new SupervisorVerificationPolicy());
        $second->recordVerification($this->confirmation('david'), $team, new SupervisorVerificationPolicy());

        self::assertTrue($uci->hasApprovalAuthority());
        self::assertFalse($urgencias->hasApprovalAuthority(), 'Authority for UCI says nothing about Urgencias.');
        self::assertTrue($second->hasApprovalAuthority(), 'Nothing limits a pool to one supervisor.');
    }

    private function pending(): SupervisorAssignment
    {
        return SupervisorAssignment::requestVerification('a-uci', self::LAURA, self::POOL, 'invitation-1', str_repeat('a', 64), new DateTimeImmutable('2026-09-25T10:00:00+00:00'));
    }

    private function verified(): SupervisorAssignment
    {
        $assignment = $this->pending();
        $team = $this->team('ana', 'david');
        $assignment->recordVerification($this->confirmation('ana', SupervisorVerificationSource::INVITATION), $team, new SupervisorVerificationPolicy());
        $assignment->recordVerification($this->confirmation('david'), $team, new SupervisorVerificationPolicy());
        self::assertTrue($assignment->hasApprovalAuthority());

        return $assignment;
    }

    private function team(string ...$members): SwapPoolTeam
    {
        return new SwapPoolTeam(self::POOL, array_values($members));
    }

    private function confirmation(string $worker, SupervisorVerificationSource $source = SupervisorVerificationSource::TEAM_MEMBER): SupervisorVerification
    {
        return $this->answer($worker, SupervisorVerificationDecision::CONFIRMED, $source);
    }

    private function answer(string $worker, SupervisorVerificationDecision $decision, SupervisorVerificationSource $source = SupervisorVerificationSource::TEAM_MEMBER): SupervisorVerification
    {
        ++$this->sequence;

        return new SupervisorVerification('v'.$this->sequence, $worker, $decision, $source, new DateTimeImmutable('2026-09-25T10:'.str_pad((string) $this->sequence, 2, '0', \STR_PAD_LEFT).':00+00:00'));
    }
}
