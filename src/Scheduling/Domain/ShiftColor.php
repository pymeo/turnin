<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use App\SharedKernel\Domain\ShiftKind;

/** A stable presentation token. It never participates in shift compatibility. */
enum ShiftColor: string
{
    case AMBER = 'amber';
    case ORANGE = 'orange';
    case TEAL = 'teal';
    case BLUE = 'blue';
    case INDIGO = 'indigo';
    case VIOLET = 'violet';
    case ROSE = 'rose';
    case SLATE = 'slate';
    case EMERALD = 'emerald';
    case CYAN = 'cyan';

    /**
     * The name a person reads when picking one. The key stays English and
     * stable because it is persisted; the label is interface copy.
     */
    public function label(): string
    {
        return match ($this) {
            self::AMBER => 'Ámbar',
            self::ORANGE => 'Naranja',
            self::TEAL => 'Verde azulado',
            self::BLUE => 'Azul',
            self::INDIGO => 'Índigo',
            self::VIOLET => 'Violeta',
            self::ROSE => 'Frambuesa',
            self::SLATE => 'Pizarra',
            self::EMERALD => 'Esmeralda',
            self::CYAN => 'Cian',
        };
    }

    /**
     * The whole palette, in the order the picker shows it. Ten accessible tones
     * rather than a free hex field: contrast, dark mode and a calendar that
     * still reads as one product are worth more than an arbitrary colour.
     *
     * @return list<self>
     */
    public static function palette(): array
    {
        return self::cases();
    }

    public static function fromKeyOrSuggestion(?string $key, ShiftKind $kind): self
    {
        return self::tryFrom((string) $key) ?? self::suggestedFor($kind);
    }

    public static function suggestedFor(ShiftKind $kind): self
    {
        return match ($kind) {
            ShiftKind::MORNING => self::AMBER,
            ShiftKind::EVENING => self::ORANGE,
            ShiftKind::NIGHT, ShiftKind::LONG_NIGHT => self::BLUE,
            ShiftKind::LONG_DAY => self::EMERALD,
            ShiftKind::ON_CALL => self::VIOLET,
            ShiftKind::OTHER => self::SLATE,
        };
    }
}
