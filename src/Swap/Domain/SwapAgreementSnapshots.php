<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface SwapAgreementSnapshots
{
    /** Returns the existing record when a repeated accept races the first one. */
    public function saveIfMissing(SwapAgreementSnapshot $snapshot): SwapAgreementSnapshot;

    public function byProposal(string $proposalId): ?SwapAgreementSnapshot;

    public function byPublicToken(string $publicToken): ?SwapAgreementSnapshot;

    public function save(SwapAgreementSnapshot $snapshot): void;
}
