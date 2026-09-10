<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Persistence\Doctrine;

use App\Platform\Identity\Domain\UserId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class UserIdType extends Type
{
    public const NAME = 'identity_user_id';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?UserId
    {
        if (null === $value || $value instanceof UserId) {
            return $value;
        }
        if (!\is_string($value)) {
            throw new ConversionException('Cannot convert database value to '.UserId::class.'.');
        }

        return new UserId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof UserId) {
            throw new ConversionException('Cannot persist a value that is not a '.UserId::class.'.');
        }

        return $value->value;
    }
}
