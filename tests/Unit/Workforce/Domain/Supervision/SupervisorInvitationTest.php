<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Domain\Supervision;

use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorInvitation;
use App\Workforce\Domain\Supervision\SupervisorInvitationStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupervisorInvitation::class)]
final class SupervisorInvitationTest extends TestCase
{
    public function test_accepting_twice_is_idempotent_for_the_same_person(): void
    {
        $invitation = $this->invitation();

        self::assertTrue($invitation->accept('laura', $this->at('2026-09-26')));
        self::assertFalse($invitation->accept('laura', $this->at('2026-09-26')));
        self::assertSame(SupervisorInvitationStatus::ACCEPTED, $invitation->status());
        self::assertSame('laura', $invitation->respondedByUserId());
    }

    public function test_somebody_else_cannot_reuse_an_accepted_invitation(): void
    {
        $invitation = $this->invitation();
        $invitation->accept('laura', $this->at('2026-09-26'));

        $this->expectException(SupervisionRejected::class);
        $invitation->accept('mallory', $this->at('2026-09-26'));
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $invitation = $this->invitation();

        self::assertTrue($invitation->isExpired($this->at('2026-10-03')));
        $this->expectException(SupervisionRejected::class);
        $this->expectExceptionMessage('caducado');
        $invitation->accept('laura', $this->at('2026-10-03'));
    }

    public function test_the_inviter_cannot_accept_their_own_invitation(): void
    {
        $this->expectException(SupervisionRejected::class);
        $this->invitation()->accept('ana', $this->at('2026-09-26'));
    }

    public function test_declining_closes_it_without_accepting(): void
    {
        $invitation = $this->invitation();

        self::assertTrue($invitation->decline('laura', $this->at('2026-09-26')));
        self::assertFalse($invitation->decline('laura', $this->at('2026-09-26')));
        self::assertSame(SupervisorInvitationStatus::DECLINED, $invitation->status());
        $this->expectException(SupervisionRejected::class);
        $invitation->accept('laura', $this->at('2026-09-26'));
    }

    public function test_a_newer_link_supersedes_a_pending_one(): void
    {
        $invitation = $this->invitation();
        $invitation->supersede($this->at('2026-09-26'));

        self::assertSame(SupervisorInvitationStatus::SUPERSEDED, $invitation->status());
        self::assertFalse($invitation->isOpen($this->at('2026-09-26')));
    }

    private function invitation(): SupervisorInvitation
    {
        return SupervisorInvitation::create('inv-1', 'pool-uci', 'ana', str_repeat('a', 64), $this->at('2026-10-02'), $this->at('2026-09-25'));
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.'T10:00:00+00:00');
    }
}
