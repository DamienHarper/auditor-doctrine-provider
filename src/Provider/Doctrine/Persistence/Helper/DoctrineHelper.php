<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Helper;

use DH\Auditor\Tests\Provider\Doctrine\Persistence\Helper\DoctrineHelperTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @see DoctrineHelperTest
 *
 * @internal
 */
final class DoctrineHelper
{
    /**
     * Type names by type class, per type provider (DBAL 4.6+).
     *
     * @var null|\WeakMap<object, array<class-string<Type>, false|string>>
     */
    private static ?\WeakMap $typeNames = null;

    /**
     * Type names by type class, from the global type registry (DBAL < 4.6).
     *
     * @var array<class-string<Type>, false|string>
     */
    private static array $legacyTypeNames = [];

    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * Doctrine ORM uses __CG__ (Common Gateway) marker for proxy classes.
     * Note: With PHP 8.4+, Doctrine uses native lazy objects instead.
     */
    public static function getRealClassName(object|string $subject): string
    {
        $subject = \is_object($subject) ? $subject::class : $subject;

        // __CG__: Doctrine Proxy Marker (Doctrine\Persistence\Proxy::MARKER)
        $position = mb_strrpos($subject, '\__CG__\\');
        if (false === $position) {
            return $subject;
        }

        return mb_substr($subject, $position + 8);
    }

    public static function setPrimaryKey(Table $table, string $columnName): void
    {
        /** @var non-empty-string $columnName */
        // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
        $table->addPrimaryKeyConstraint(new PrimaryKeyConstraint(null, [new UnqualifiedName(Identifier::unquoted($columnName))], true));
    }

    public static function getReflectionPropertyValue(ClassMetadata $meta, string $name, object $entity): mixed
    {
        return $meta->getPropertyAccessor($name)?->getValue($entity);
    }

    public static function jsonStringType(): string
    {
        return \defined(Types::class.'::JSONB') ? Types::JSONB : Types::JSON;
    }

    /**
     * @return string[]
     */
    public static function jsonStringTypes(): array
    {
        return [Types::JSON, 'jsonb'];
    }

    /**
     * @return Type[]
     */
    public static function jsonTypes(Connection $connection): array
    {
        $jsonTypes = [self::getType($connection, Types::JSON)];
        if (\defined(Types::class.'::JSONB')) {
            $jsonTypes[] = self::getType($connection, Types::JSONB);
        }

        return $jsonTypes;
    }

    /**
     * Whether the DBAL schema editor API (Schema::edit(), Table::edit()...) is available (DBAL 4.5+).
     */
    public static function hasSchemaEditorApi(): bool
    {
        return method_exists(Schema::class, 'edit');
    }

    /**
     * Resolves a DBAL type by name.
     *
     * DBAL 4.6+ resolves types through the type provider of the connection; older versions only
     * have the global type registry.
     *
     * @throws Exception
     */
    public static function getType(Connection $connection, string $name): Type
    {
        $configuration = $connection->getConfiguration();
        if (method_exists($configuration, 'getTypeProvider')) {
            return $configuration->getTypeProvider()->get($name);
        }

        // @phpstan-ignore staticMethod.deprecated (DBAL < 4.6 compatibility path)
        return Type::getType($name);
    }

    /**
     * Returns the name of the first type registered with the class of the given type instance,
     * or false when there is none.
     *
     * DBAL 4.6+ looks it up in the type provider of the connection; older versions in the global
     * type registry. Lookups are cached per type provider (or globally on older versions).
     */
    public static function getTypeName(Connection $connection, Type $type): false|string
    {
        $configuration = $connection->getConfiguration();
        if (!method_exists($configuration, 'getTypeProvider')) {
            // @phpstan-ignore staticMethod.deprecated (DBAL < 4.6 compatibility path)
            return self::$legacyTypeNames[$type::class] ??= array_search($type::class, Type::getTypesMap(), true);
        }

        $provider = $configuration->getTypeProvider();
        self::$typeNames ??= new \WeakMap();
        $names = self::$typeNames[$provider] ?? [];

        if (!\array_key_exists($type::class, $names)) {
            $names[$type::class] = false;
            foreach ($provider as $name => $candidate) {
                if ($candidate::class === $type::class) {
                    $names[$type::class] = $name;

                    break;
                }
            }

            self::$typeNames[$provider] = $names;
        }

        return $names[$type::class];
    }
}
