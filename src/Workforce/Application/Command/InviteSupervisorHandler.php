<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Application\Supervision\SupervisionShareMessages;
use App\Workforce\Domain\Supervision\SupervisionIdGenerator;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorInvitation;
use App\Workforce\Domain\Supervision\SupervisorInvitations;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use DateInterval;
use Psr\Clock\ClockInterface;

/**
 * A colleague creates the link for the person who usually validates their
 * changes. One live link per colleague and pool: a new one replaces the last,
 * so pressing the button twice does not leave a trail of valid tokens.
 */
final readonly class InviteSupervisorHandler
{
    public function __construct(
        private SupervisorInvitations $invitations,
        private SupervisorAssignments $assignments,
        private SwapPoolTeams $teams,
        private SupervisorLinkTokens $tokens,
        private SupervisionIdGenerator $ids,
        private ClockInterface $clock,
        private int $supervisorInvitationTtlDays,
    ) {
    }

    public function __invoke(InviteSupervisor $command): SupervisorInvitationCreated
    {
        if (!$this->teams->team($command->swapPoolId)->includes($command->workerId)) {
            throw new SupervisionRejected('Solo los miembros del equipo pueden invitar a su responsable.');
        }
        $pool = $this->teams->describe($command->swapPoolId) ?? throw new SupervisionRejected('Este equipo ya no está disponible.');
        $now = $this->clock->now();
        foreach ($this->invitations->pendingFrom($command->workerId, $command->swapPoolId) as $previous) {
            $previous->supersede($now);
            $this->invitations->save($previous);
        }
        $token = $this->tokens->newInvitationToken();
        $expiresAt = $now->add(new DateInterval('P'.max(1, $this->supervisorInvitationTtlDays).'D'));
        $this->invitations->save(SupervisorInvitation::create($this->ids->next(), $command->swapPoolId, $command->workerId, $token['hash'], $expiresAt, $now));
        $verified = \count(array_filter($this->assignments->activeInPool($command->swapPoolId), static fn (SupervisorAssignment $assignment): bool => $assignment->hasApprovalAuthority()));

        return new SupervisorInvitationCreated($token['token'], SupervisionShareMessages::invitationPath($token['token']), $expiresAt, $pool->workplaceName, $pool->teamLabel, $verified);
    }
}
