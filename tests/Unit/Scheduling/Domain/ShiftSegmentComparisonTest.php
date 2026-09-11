<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\ShiftColor;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\SharedKernel\Domain\ShiftKind;
use PHPUnit\Framework\TestCase;

/**
 * There is deliberately no `matches()` on a segment. "The same shift" means
 * different things to a calendar and to a future matcher, and one ambiguous
 * method is how a label comparison ends up deciding whether two people can swap.
 *
 * See docs/adr/0008-roster-and-calendar-model.md.
 */
final class ShiftSegmentComparisonTest extends TestCase
{
    /**
     * Pedro and Ana label the same hours differently. The hours are the truth,
     * so these two are interchangeable work even though nothing else matches.
     */
    public function test_different_labels_over_the_same_hours_are_the_same_work(): void
    {
        $pedro = $this->segment('Mañana', 'M', '07:00', '15:00', ShiftKind::MORNING, ShiftColor::AMBER);
        $ana = $this->segment('Mañana larga', 'MA', '07:00', '15:00', ShiftKind::MORNING, ShiftColor::CYAN);

        self::assertTrue($pedro->sameLocalHoursAs($ana));
        self::assertTrue($pedro->sameDurationAs($ana));
        self::assertFalse($pedro->samePresentationAs($ana));
    }

    /**
     * The mirror case, and the dangerous one: the same letter over different
     * hours. A matcher keying on the abbreviation would call these compatible.
     */
    public function test_the_same_label_over_different_hours_is_not_the_same_work(): void
    {
        $pedro = $this->segment('Mañana', 'M', '07:00', '15:00', ShiftKind::MORNING, ShiftColor::AMBER);
        $laura = $this->segment('Mañana', 'M', '09:00', '17:00', ShiftKind::MORNING, ShiftColor::AMBER);

        self::assertFalse($pedro->sameLocalHoursAs($laura));
        self::assertTrue($pedro->sameDurationAs($laura), 'Both are eight hours; that is all duration says.');
        self::assertTrue($pedro->samePresentationAs($laura), 'They do look identical in a cell — which is the point.');
    }

    public function test_colour_is_presentation_and_never_changes_the_hours(): void
    {
        $amber = $this->segment('Mañana', 'M', '07:00', '15:00', ShiftKind::MORNING, ShiftColor::AMBER);
        $rose = $this->segment('Mañana', 'M', '07:00', '15:00', ShiftKind::MORNING, ShiftColor::ROSE);

        self::assertTrue($amber->sameLocalHoursAs($rose));
        self::assertFalse($amber->samePresentationAs($rose));
    }

    private function segment(string $label, string $abbreviation, string $start, string $end, ShiftKind $kind, ShiftColor $color): ShiftSegment
    {
        return new ShiftSegment('s-'.$abbreviation.$start, null, $label, $abbreviation, ShiftWindow::fromStrings($start, $end), $kind, 0, $color);
    }
}
