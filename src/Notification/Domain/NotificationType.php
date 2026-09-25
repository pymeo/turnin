<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum NotificationType: string
{
    case SWAP_PROPOSAL = 'swap_proposal';
    case SWAP_AGREEMENT = 'swap_agreement';
    case SWAP_APPROVED = 'swap_approved';
    case SWAP_REJECTED = 'swap_rejected';
    case SWAP_APPROVAL_REQUESTED = 'swap_approval_requested';
    case SUPERVISOR_VERIFICATION_REQUESTED = 'supervisor_verification';
    case SUPERVISOR_INVITATION_ANSWERED = 'supervisor_invitation';
    case SUPERVISOR_VERIFIED = 'supervisor_verified';
    case SUPERVISOR_PENDING_APPROVALS = 'supervisor_pending_approvals';
    case SUPERVISOR_TEAM_UPDATE = 'supervisor_team_update';
}
