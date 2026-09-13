<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use App\Scheduling\Domain\CalendarBlockType;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\SegmentProposal;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\SpokenTerm;
use App\Scheduling\Domain\WorkDate;
use DateTimeZone;

/** Converts provider-neutral events to a draft without trusting their labels for hours. */
final readonly class ExternalCalendarScheduleImporter
{
    /** @param list<ExternalCalendarEvent> $events */
    public function prepare(string $workerAssignmentId, array $events, ShiftPresetResolver $presets, DateTimeZone $timeZone): CalendarImportPlan
    {
        $items = [];
        foreach ($events as $event) {
            $localStart = $event->startsAt->setTimezone($timeZone);
            $localEnd = $event->endsAt->setTimezone($timeZone);
            $date = WorkDate::fromString($localStart->format('Y-m-d'));
            if ($event->cancelled) {
                $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::unresolved($date, 'El evento está eliminado.'), 'ignored', $event->title, $event);
                continue;
            }
            if ($event->allDay) {
                $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::unresolved($date, 'Se importará como evento personal de día completo.'), 'personal', $event->title, $event, $this->blockType($event->title));
                continue;
            }
            $duration = $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp();
            if ($duration <= 0) {
                $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::unresolved($date, 'Revisa este evento: no tiene una duración válida.'), 'review', $event->title, $event);
                continue;
            }
            if ($duration > 86400) {
                $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::unresolved($date, 'Se importará como evento personal de varios días.'), 'personal', $event->title, $event, $this->blockType($event->title));
                continue;
            }
            $preset = $this->recognize($event->title, $presets);
            if (null === $preset) {
                $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::unresolved($date, 'Se importará como evento personal.'), 'personal', $event->title, $event, $this->blockType($event->title));
                continue;
            }

            // Metadata comes from the preset; the real hours always come from Google.
            $window = ShiftWindow::fromStrings($localStart->format('H:i'), $localEnd->format('H:i'));
            $proposal = new SegmentProposal($preset->id(), $preset->name(), $preset->abbreviation(), $window, $preset->kind(), $preset->color());
            $items[] = new CalendarImportItem($event->id, ScheduleDraftEntry::work($date, [$proposal]), 'recognized', $event->title, $event);
        }

        return new CalendarImportPlan($workerAssignmentId, $items);
    }

    private function recognize(string $title, ShiftPresetResolver $presets): ?ShiftPreset
    {
        $direct = $presets->bySpokenForm($title);
        if (null !== $direct) {
            return $direct;
        }
        $normalized = SpokenTerm::normalize($title);
        foreach ($presets->spokenForms() as $form) {
            $candidate = SpokenTerm::normalize($form);
            if (mb_strlen($candidate) >= 2 && preg_match('/(?:^|\s)'.preg_quote($candidate, '/').'(?:$|\s)/u', $normalized)) {
                return $presets->bySpokenForm($form);
            }
        }

        return null;
    }

    private function blockType(string $title): CalendarBlockType
    {
        $normalized = SpokenTerm::normalize($title);

        return match (true) {
            str_contains($normalized, 'vacacion') => CalendarBlockType::VACATION,
            str_contains($normalized, 'juicio'), str_contains($normalized, 'abogado') => CalendarBlockType::LEGAL,
            str_contains($normalized, 'viaje') => CalendarBlockType::TRAVEL,
            str_contains($normalized, 'cita'), str_contains($normalized, 'dentista'), str_contains($normalized, 'medico') => CalendarBlockType::APPOINTMENT,
            str_contains($normalized, 'asunto propio'), str_contains($normalized, 'permiso') => CalendarBlockType::PERSONAL_LEAVE,
            default => CalendarBlockType::PERSONAL,
        };
    }
}
