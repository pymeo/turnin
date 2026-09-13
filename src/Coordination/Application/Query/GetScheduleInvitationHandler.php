<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use App\Coordination\Domain\InvitationTokenGenerator;
use App\Coordination\Domain\ScheduleInvitations;
use App\Coordination\Domain\ScheduleInvitationStatus;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class GetScheduleInvitationHandler
{
    public function __construct(private ScheduleInvitations $invitations, private InvitationTokenGenerator $tokens, private CoordinationDisplayNames $names, private ClockInterface $clock)
    {
    }

    public function __invoke(GetScheduleInvitation $query): ScheduleInvitationView
    {
        $invitation = $this->invitations->byTokenHash($this->tokens->hash($query->plainToken));
        if (null === $invitation) {
            throw new InvalidArgumentException('Esta invitación no existe.');
        }
        $names = $this->names->forUsers([$invitation->inviterId()]);

        return new ScheduleInvitationView($names[$invitation->inviterId()] ?? 'Alguien importante para ti', $invitation->expiresAt(), ScheduleInvitationStatus::PENDING === $invitation->status() && $this->clock->now() < $invitation->expiresAt());
    }
}
