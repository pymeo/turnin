<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use App\Coordination\Domain\ScheduleLinks;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class RevokeScheduleLinkHandler
{
    public function __construct(private ScheduleLinks $links, private ClockInterface $clock)
    {
    }

    public function __invoke(RevokeScheduleLink $command): void
    {
        $link = $this->links->activeFor($command->actorId);
        if (null === $link) {
            throw new InvalidArgumentException('No tienes un calendario vinculado.');
        }
        $link->revoke($command->actorId, $this->clock->now());
        $this->links->save($link);
    }
}
