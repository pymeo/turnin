<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum NotificationType: string
{
    case SWAP_PROPOSAL = 'swap_proposal';
    case SWAP_AGREEMENT = 'swap_agreement';
    case SWAP_APPROVED = 'swap_approved';
    case SWAP_REJECTED = 'swap_rejected';
}
