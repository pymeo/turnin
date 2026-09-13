<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface SwapProposals
{
    public function save(SwapProposal $proposal): void;

    public function byId(string $id): ?SwapProposal;

    public function byIdForUpdate(string $id): ?SwapProposal;

    /** @return list<SwapProposal> */
    public function involving(string $workerId): array;

    /** @param list<string> $poolIds
     * @return list<SwapProposal>
     */
    public function awaitingApprovalInPools(array $poolIds): array;

    public function activeRedemption(string $balanceId, string $requestId, string $proposerId): ?SwapProposal;

    public function pendingApprovalForRequest(string $requestId): ?SwapProposal;
}
