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

    /**
     * Everything still able to win this request. Once one proposal executes the
     * rest cannot, and leaving them "pending" would promise something the
     * roster can no longer deliver.
     *
     * @return list<SwapProposal>
     */
    public function liveForRequest(string $requestId): array;

    /** @param list<string> $poolIds
     * @return list<SwapProposal>
     */
    public function awaitingApprovalInPools(array $poolIds): array;

    public function activeRedemption(string $balanceId, string $requestId, string $proposerId): ?SwapProposal;

    public function pendingApprovalForRequest(string $requestId): ?SwapProposal;
}
