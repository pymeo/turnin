<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Twig;

use App\Swap\Application\Query\GetPendingApprovalCounts;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SupervisorApprovalExtension extends AbstractExtension
{
    public function __construct(private readonly MessageBusInterface $queryBus)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('pending_approval_counts', $this->counts(...))];
    }

    /** @return array<string, int> */
    public function counts(string $supervisorUserId): array
    {
        if ('' === $supervisorUserId) {
            return [];
        }
        $counts = $this->queryBus->dispatch(new GetPendingApprovalCounts($supervisorUserId))->last(HandledStamp::class)?->getResult();

        $valid = [];
        foreach (\is_array($counts) ? $counts : [] as $pool => $waiting) {
            if (\is_int($waiting)) {
                $valid[(string) $pool] = $waiting;
            }
        }

        return $valid;
    }
}
