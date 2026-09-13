<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use App\Coordination\Domain\CoordinationIdGenerator;
use App\Coordination\Domain\InvitationTokenGenerator;
use App\Coordination\Domain\ScheduleInvitations;
use App\Coordination\Domain\ScheduleLink;
use App\Coordination\Domain\ScheduleLinks;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class AcceptScheduleInvitationHandler
{
    public function __construct(private ScheduleInvitations $invitations, private ScheduleLinks $links, private InvitationTokenGenerator $tokens, private CoordinationIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(AcceptScheduleInvitation $command): string
    {
        $invitation = $this->invitations->byTokenHash($this->tokens->hash($command->plainToken));
        if (null === $invitation) {
            throw new InvalidArgumentException('Esta invitación no existe o ya no está disponible.');
        }
        $invitation->accept($command->guestId, $this->clock->now());
        if (null !== $this->links->activeBetween($invitation->inviterId(), $command->guestId)) {
            throw new InvalidArgumentException('Estos calendarios ya están vinculados.');
        }
        $link = ScheduleLink::create($this->ids->next(), $invitation->inviterId(), $command->guestId, $this->clock->now());
        $this->invitations->save($invitation);
        $this->links->save($link);

        return $link->id();
    }
}
