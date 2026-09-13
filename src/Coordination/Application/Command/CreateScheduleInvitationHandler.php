<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use App\Coordination\Domain\CoordinationIdGenerator;
use App\Coordination\Domain\InvitationTokenGenerator;
use App\Coordination\Domain\ScheduleInvitation;
use App\Coordination\Domain\ScheduleInvitations;
use DateInterval;
use Psr\Clock\ClockInterface;

final readonly class CreateScheduleInvitationHandler
{
    public function __construct(private ScheduleInvitations $invitations, private CoordinationIdGenerator $ids, private InvitationTokenGenerator $tokens, private ClockInterface $clock)
    {
    }

    public function __invoke(CreateScheduleInvitation $command): ScheduleInvitationCreated
    {
        $now = $this->clock->now();
        $token = $this->tokens->generate();
        $expiresAt = $now->add(new DateInterval('P7D'));
        $this->invitations->save(ScheduleInvitation::create($this->ids->next(), $command->inviterId, $token->hash, $expiresAt, $now));

        return new ScheduleInvitationCreated($token->plain, $expiresAt);
    }
}
