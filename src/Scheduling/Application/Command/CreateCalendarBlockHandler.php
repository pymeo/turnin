<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\CalendarBlock;
use App\Scheduling\Domain\CalendarBlockIdGenerator;
use App\Scheduling\Domain\CalendarBlocks;
use App\Scheduling\Domain\CalendarBlockSource;
use App\Scheduling\Domain\CalendarBlockType;
use Psr\Clock\ClockInterface;

final readonly class CreateCalendarBlockHandler
{
    public function __construct(private CalendarBlocks $blocks, private CalendarBlockIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(CreateCalendarBlock $command): CalendarBlockCreated
    {
        $block = CalendarBlock::create($this->ids->next(), $command->workerId, $command->title, CalendarBlockType::from($command->type), $command->startsAt, $command->endsAt, $command->allDay, $command->blocksAvailability, CalendarBlockSource::MANUAL, $this->clock->now());
        $this->blocks->save($block);

        return new CalendarBlockCreated($block->id());
    }
}
