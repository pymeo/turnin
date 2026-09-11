<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * "1 y 2 mañana, 3 tarde, del 4 al 6 noche" → a ScheduleDraft.
 *
 * Deterministic and offline by construction: no model, no external call, no
 * network. Three reasons, in order. A roster is the most sensitive thing a
 * worker gives us and it is not leaving the box. A parser that is wrong the
 * same way every time can be fixed by a test; one that is wrong differently
 * every time cannot. And dictating a rota has to work in a hospital corridor
 * on a bad connection.
 *
 * Voice never reaches this class directly — the browser produces a transcript,
 * the transcript is text, and text is the only input this parser has.
 */
final readonly class ScheduleTextParser
{
    private const REST_FORMS = ['libre', 'libres', 'descanso', 'descansos', 'fiesta', 'fiestas', 'l', 'off', 'saliente'];

    private const PATTERN_MARKERS = ['mi patron es', 'mi patron', 'el patron es', 'patron', 'mi rotacion es', 'mi rotacion', 'rotacion', 'se repite', 'mi ciclo es', 'ciclo'];

    public function __construct(private DateExpressionParser $dates)
    {
    }

    public function parse(string $text, RosterMonth $month, ShiftPresetResolver $presets, RosterSource $source): ScheduleTextReading
    {
        $normalized = SpokenTerm::normalize($text);
        if ('' === $normalized) {
            return new ScheduleTextReading(ScheduleDraft::empty($source), null, []);
        }

        $patternBody = $this->patternBody($normalized);
        if (null !== $patternBody) {
            return $this->readPattern($patternBody, $presets, $source);
        }

        return $this->readDays($normalized, $month, $presets, $source);
    }

    private function readDays(string $normalized, RosterMonth $month, ShiftPresetResolver $presets, RosterSource $source): ScheduleTextReading
    {
        $matches = $this->locateShiftTerms($normalized, $presets);
        if ([] === $matches) {
            return new ScheduleTextReading(ScheduleDraft::empty($source), null, [trim($normalized)]);
        }

        $entries = [];
        $unrecognized = [];
        $cursor = 0;

        foreach ($matches as [$offset, $length, $preset]) {
            $expression = substr($normalized, $cursor, $offset - $cursor);
            $cursor = $offset + $length;

            $days = $this->dates->expand($expression);
            if ([] === $days) {
                $fragment = trim($expression.' '.substr($normalized, $offset, $length));
                if ('' !== $fragment) {
                    $unrecognized[] = $fragment;
                }
                continue;
            }

            foreach ($days as $day) {
                $date = $month->dayOrNull($day);
                if (null === $date) {
                    $unrecognized[] = \sprintf('El día %d no existe en %s.', $day, $month);
                    continue;
                }
                $entries[] = null === $preset
                    ? ScheduleDraftEntry::rest($date)
                    : ScheduleDraftEntry::work($date, [SegmentProposal::fromPreset($preset)]);
            }
        }

        $tail = trim(substr($normalized, $cursor));
        if ('' !== $tail && 1 === preg_match('/[a-z0-9]/', $tail)) {
            $unrecognized[] = $tail;
        }

        return new ScheduleTextReading(ScheduleDraft::of($entries, $source), null, $unrecognized);
    }

    /**
     * "mañana mañana tarde tarde noche noche y tres libres" — a bare sequence,
     * where a number means "this many of the next thing".
     */
    private function readPattern(string $body, ShiftPresetResolver $presets, RosterSource $source): ScheduleTextReading
    {
        $matches = $this->locateShiftTerms($body, $presets);
        $slots = [];
        $unrecognized = [];
        $cursor = 0;

        foreach ($matches as [$offset, $length, $preset]) {
            $between = substr($body, $cursor, $offset - $cursor);
            $cursor = $offset + $length;
            $repeats = $this->leadingCount($between);
            $slot = null === $preset ? PatternSlotProposal::rest() : PatternSlotProposal::fromPreset($preset);
            for ($index = 0; $index < $repeats; ++$index) {
                $slots[] = $slot;
            }
        }

        $tail = trim(substr($body, $cursor));
        if ('' !== $tail && 1 === preg_match('/[a-z0-9]/', $tail)) {
            $unrecognized[] = $tail;
        }
        if ([] === $slots) {
            $unrecognized[] = trim($body);
        }

        return new ScheduleTextReading(ScheduleDraft::empty($source), new PatternDraft($slots), $unrecognized);
    }

    /**
     * Every shift word in the text, with where it sits, longest form first so
     * "turno de noche" wins over the "noche" inside it. A null preset means the
     * word was a rest word.
     *
     * @return list<array{int, int, ShiftPreset|null}>
     */
    private function locateShiftTerms(string $normalized, ShiftPresetResolver $presets): array
    {
        $forms = [];
        foreach ($presets->spokenForms() as $form) {
            $forms[$form] = $presets->bySpokenForm($form);
        }
        foreach (self::REST_FORMS as $form) {
            // A worker who named a preset "libre" would be describing rest days
            // anyway, so an existing match is left alone.
            $forms[$form] ??= null;
        }

        $ordered = array_keys($forms);
        usort($ordered, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $found = [];
        $claimed = [];
        foreach ($ordered as $form) {
            $pattern = '/(?<![a-z0-9])'.preg_quote($form, '/').'(?![a-z0-9])/u';
            if (false === preg_match_all($pattern, $normalized, $hits, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            /** @var list<array{string, int}> $hits0 */
            $hits0 = $hits[0];
            foreach ($hits0 as $hit) {
                $offset = $hit[1];
                $length = \strlen($hit[0]);
                if ($this->overlapsClaimed($claimed, $offset, $length)) {
                    continue;
                }
                $claimed[] = [$offset, $length];
                $found[] = [$offset, $length, $forms[$form]];
            }
        }

        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
    }

    /** @param list<array{int, int}> $claimed */
    private function overlapsClaimed(array $claimed, int $offset, int $length): bool
    {
        foreach ($claimed as [$start, $span]) {
            if ($offset < $start + $span && $start < $offset + $length) {
                return true;
            }
        }

        return false;
    }

    private function patternBody(string $normalized): ?string
    {
        foreach (self::PATTERN_MARKERS as $marker) {
            if (1 === preg_match('/(?<![a-z])'.preg_quote($marker, '/').'(?![a-z])/u', $normalized, $hit, \PREG_OFFSET_CAPTURE)) {
                return trim(substr($normalized, (int) $hit[0][1] + \strlen((string) $hit[0][0])));
            }
        }

        return null;
    }

    /** How many times the next shift word repeats: "tres libres" is three. */
    private function leadingCount(string $between): int
    {
        $tokens = preg_split('/[^a-z0-9]+/u', trim($between), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        for ($index = \count($tokens) - 1; $index >= 0; --$index) {
            $number = $this->dates->toNumber((string) $tokens[$index]);
            if (null !== $number) {
                return max(1, min(31, $number));
            }
        }

        return 1;
    }
}
