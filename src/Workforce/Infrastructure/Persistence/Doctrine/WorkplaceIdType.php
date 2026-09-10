<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\WorkplaceId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class WorkplaceIdType extends Type
{
    public const NAME = 'workplace_id';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?WorkplaceId
    {
        if (null === $value || $value instanceof WorkplaceId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw new ConversionException('Cannot convert a non-string database value to '.WorkplaceId::class.'.');
        }

        return new WorkplaceId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof WorkplaceId) {
            throw new ConversionException('Cannot persist a value that is not a '.WorkplaceId::class.'.');
        }

        return $value->value;
    }
}
