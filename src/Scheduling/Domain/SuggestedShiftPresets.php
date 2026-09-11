<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use App\SharedKernel\Domain\ShiftKind;

/**
 * What we put in front of somebody opening the calendar for the first time.
 *
 * Three bands with the most common Spanish hospital hours — a starting point,
 * not a claim. The screen says "Ajustar horarios" right underneath, because the
 * one thing we can be sure of is that some centre starts the morning at 07:00.
 *
 * When a SwapPool eventually publishes the hours its centre actually uses, this
 * is the list it replaces. Nothing else has to change, which is the reason the
 * suggestion lives behind a named concept instead of inline in a handler.
 */
final readonly class SuggestedShiftPresets
{
    /** @return list<ShiftPresetBlueprint> */
    public static function catalogue(): array
    {
        return [
            new ShiftPresetBlueprint('Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, ['manana', 'turno de manana', 'mananas'], ShiftColor::AMBER),
            new ShiftPresetBlueprint('Tarde', 'T', ShiftWindow::fromStrings('15:00', '22:00'), ShiftKind::EVENING, ['tarde', 'turno de tarde', 'tardes'], ShiftColor::ORANGE),
            new ShiftPresetBlueprint('Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, ['noche', 'turno de noche', 'noches'], ShiftColor::BLUE),
        ];
    }
}
