<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class SwapAgreementView
{
    public function __construct(
        public string $proposalId,
        public string $requestOwnerName,
        public string $proposerName,
        public AgreementLegView $requestedLeg,
        public ?AgreementLegView $returnLeg,
        public string $difference,
        public string $status,
        public string $statusTitle,
        public string $statusDetail,
        public bool $pendingApproval,
        public bool $cancelled,
        public string $reachedAt,
        public string $reference,
        public string $publicToken,
        public bool $revoked,
        public ?string $approvedByName = null,
    ) {
    }
}
