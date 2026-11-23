<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Custom Doctrine type: a Money value object stored as "12.34 EUR".
 */
final class MoneyType extends Type
{
    public const string NAME = 'e2e_money';

    public static function register(): void
    {
        if (!Type::hasType(self::NAME)) {
            Type::addType(self::NAME, self::class);
        }
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 32] + $column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Money
    {
        if (null === $value || '' === $value) {
            return null;
        }

        [$amount, $currency] = explode(' ', (string) $value, 2);

        return new Money($amount, $currency);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value instanceof Money ? (string) $value : null;
    }
}
