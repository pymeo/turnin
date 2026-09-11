<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;

/**
 * Turns "I tapped this card" into "declare me available in that group on that
 * day", resolving the pool and the assignment from the session.
 *
 * The request is re-fetched and its visibility re-checked here: a request id
 * from a browser proves nothing about whether its owner shares a group with the
 * person tapping.
 */
final readonly class GetOfferContextHandler
{
    public function __construct(private SwapWorkspace $workspace, private Availabilities $availabilities)
    {
    }

    public function __invoke(GetOfferContext $query): OfferContext
    {
        $request = $this->workspace->requireVisibleRequest($query->workerId, $query->requestId);
        if ($request->workerId() === $query->workerId) {
            throw SwapAccessDenied::notAMember();
        }
        if (!$request->isOpen()) {
            throw SwapAccessDenied::notYours();
        }

        // The membership is what says which of the worker's own assignments
        // reaches that pool; the request's assignment belongs to somebody else.
        $group = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());
        $existing = $this->availabilities->forSlot($query->workerId, $group->poolId, $request->workDate(), $request->shiftKind());

        return new OfferContext(
            $request->id(),
            $group->poolId,
            $group->assignmentId,
            (string) $request->workDate(),
            $request->shiftKind()->value,
            null !== $existing && $existing->isActive(),
        );
    }
}
