<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coordination\Domain;

use App\Coordination\Domain\ScheduleInvitation;
use App\Coordination\Domain\ScheduleInvitationStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleInvitationTest extends TestCase
{
    public function test_it_is_single_use_and_cannot_link_the_inviter_to_themself(): void
    {
        $now = new DateTimeImmutable('2026-09-12T10:00:00+02:00');
        $invitation = ScheduleInvitation::create('invite-a', 'user-a', str_repeat('a', 64), $now->modify('+7 days'), $now);

        $invitation->accept('user-b', $now->modify('+1 hour'));
        self::assertSame(ScheduleInvitationStatus::ACCEPTED, $invitation->status());
        self::assertSame('user-b', $invitation->acceptedBy());

        $this->expectException(InvalidArgumentException::class);
        $invitation->accept('user-c', $now->modify('+2 hours'));
    }

    public function test_expired_link_cannot_be_accepted(): void
    {
        $now = new DateTimeImmutable('2026-09-12T10:00:00+02:00');
        $invitation = ScheduleInvitation::create('invite-a', 'user-a', str_repeat('a', 64), $now->modify('+1 hour'), $now);

        $this->expectExceptionMessage('caducado');
        $invitation->accept('user-b', $now->modify('+1 hour'));
    }

    public function test_self_invitation_is_refused(): void
    {
        $now = new DateTimeImmutable('2026-09-12T10:00:00+02:00');
        $invitation = ScheduleInvitation::create('invite-a', 'user-a', str_repeat('a', 64), $now->modify('+1 hour'), $now);

        $this->expectExceptionMessage('contigo mismo');
        $invitation->accept('user-a', $now->modify('+1 minute'));
    }
}
