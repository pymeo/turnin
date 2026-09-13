<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\ExternalCalendar\ExternalCalendarEvent;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarScheduleImporter;
use App\Scheduling\Domain\ShiftColor;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftWindow;
use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ExternalCalendarScheduleImporterTest extends TestCase
{
    public function test_google_hours_override_a_recognized_presets_hours(): void
    {
        $preset = ShiftPreset::create('preset-a', 'assignment-a', 'Mañana', 'M', ShiftWindow::fromStrings('07:00', '15:00'), ShiftKind::MORNING, ['turno mañana'], 1, new DateTimeImmutable('2026-01-01'), ShiftColor::AMBER);
        $event = new ExternalCalendarEvent('event-1', 'Turno mañana', new DateTimeImmutable('2026-09-15T09:00:00+02:00'), new DateTimeImmutable('2026-09-15T17:00:00+02:00'), false, false, new DateTimeImmutable('2026-09-01'));

        $plan = (new ExternalCalendarScheduleImporter())->prepare('assignment-a', [$event], new ShiftPresetResolver([$preset]), new DateTimeZone('Europe/Madrid'));

        self::assertSame('recognized', $plan->items[0]->status);
        self::assertSame('09:00–17:00', (string) $plan->items[0]->entry->segments[0]->window);
        self::assertSame(ShiftColor::AMBER, $plan->items[0]->entry->segments[0]->color);
        self::assertSame('assignment-a', $plan->draft()->workerAssignmentId);
    }

    public function test_unknown_and_all_day_events_become_personal_blocks_instead_of_shifts(): void
    {
        $events = [
            new ExternalCalendarEvent('noise', 'Dentista', new DateTimeImmutable('2026-09-15T10:00:00+02:00'), new DateTimeImmutable('2026-09-15T11:00:00+02:00'), false, false, new DateTimeImmutable('2026-09-01')),
            new ExternalCalendarEvent('birthday', 'Cumpleaños', new DateTimeImmutable('2026-09-16'), new DateTimeImmutable('2026-09-17'), true, false, new DateTimeImmutable('2026-09-01')),
        ];

        $plan = (new ExternalCalendarScheduleImporter())->prepare('assignment-a', $events, new ShiftPresetResolver([]), new DateTimeZone('Europe/Madrid'));

        self::assertSame(['personal', 'personal'], array_map(static fn ($item): string => $item->status, $plan->items));
        self::assertFalse($plan->items[0]->event->allDay);
        self::assertTrue($plan->items[1]->event->allDay);
        self::assertTrue($plan->draft()->isEmpty());
    }
}
