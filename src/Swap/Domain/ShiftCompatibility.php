<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The single answer to "can this person work that shift".
 *
 * Every screen in the exchange flow asks it: the list of colleagues' shifts, the
 * calendar where return options are picked, and the revalidation that runs
 * before anything is written. Having one place to ask is the point — the old
 * code approximated it with "do you work that day", which is wrong in both
 * directions: a morning does not block an evening, and a night shift blocks the
 * next morning without touching its date.
 *
 * A short interval between shifts is surfaced as an advisory, never as a hard
 * incompatibility. Turnin blocks real overlaps and organisational boundaries;
 * the professional keeps the final say when the only concern is rest time.
 * See docs/adr/0012-direct-exchange-options-and-real-interval-compatibility.md.
 */
final readonly class ShiftCompatibility
{
    public const int SHORT_REST_NOTICE_MINUTES = 720;

    private function __construct(
        public bool $compatible,
        public ?ShiftObstacle $obstacle,
        public string $explanation,
    ) {
    }

    public static function allowed(string $explanation = ''): self
    {
        return new self(true, null, $explanation);
    }

    public static function blocked(ShiftObstacle $obstacle, string $explanation): self
    {
        return new self(false, $obstacle, $explanation);
    }

    /**
     * @param non-empty-list<RosteredShift> $target   every stretch of the shift being taken on
     * @param list<RosteredShift>           $existing everything the candidate already works
     */
    public static function assess(array $target, array $existing, bool $belongsToGroup, DateTimeImmutable $now): self
    {
        if ([] === $target) {
            throw new InvalidArgumentException('Compatibility is asked about real scheduled work.');
        }
        if (!$belongsToGroup) {
            return self::blocked(ShiftObstacle::NOT_IN_GROUP, 'No perteneces al grupo de ese turno.');
        }

        foreach ($target as $shift) {
            if ($shift->startsAt <= $now) {
                return self::blocked(ShiftObstacle::ALREADY_STARTED, 'Ese turno ya ha empezado.');
            }
        }

        foreach ($target as $shift) {
            foreach ($existing as $own) {
                if ($shift->overlaps($own)) {
                    return self::blocked(ShiftObstacle::SHIFT_OVERLAP, \sprintf('Trabajas de %s a %s.', $own->startsAtLocal, $own->endsAtLocal));
                }
            }
        }

        $shortestRest = null;
        $shortestRestMessage = '';

        foreach ($target as $shift) {
            foreach ($existing as $own) {
                $restMinutes = $own->restMinutesTo($shift);
                if ($restMinutes >= self::SHORT_REST_NOTICE_MINUTES) {
                    continue;
                }

                if (null !== $shortestRest && $restMinutes >= $shortestRest) {
                    continue;
                }

                $shortestRest = $restMinutes;
                $shortestRestMessage = $own->endsAt <= $shift->startsAt
                    ? self::shortRestMessage($own->endsAtLocal, $shift->startsAtLocal, $restMinutes)
                    : self::shortRestMessage($shift->endsAtLocal, $own->startsAtLocal, $restMinutes);
            }
        }

        return self::allowed($shortestRestMessage);
    }

    private static function shortRestMessage(string $finishesAt, string $startsAgainAt, int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $rest = 0 === $remainingMinutes
            ? \sprintf('%d h', $hours)
            : \sprintf('%d h %02d min', $hours, $remainingMinutes);

        return \sprintf(
            'Terminarías a las %s, tendrías %s libres y volverías a trabajar a las %s. Puedes elegirlo igualmente.',
            $finishesAt,
            $rest,
            $startsAgainAt,
        );
    }
}
