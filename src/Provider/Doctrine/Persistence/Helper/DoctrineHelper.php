<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Helper;

use DH\Auditor\Tests\Provider\Doctrine\Persistence\Helper\DoctrineHelperTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
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
     * JSON types, per type provider.
     *
     * @var null|\WeakMap<object, list<Type>>
     */
    private static ?\WeakMap $jsonTypes = null;

    /**
     * Type names by type class, per type provider.
     *
     * @var null|\WeakMap<object, array<class-string<Type>, false|string>>
     */
    private static ?\WeakMap $typeNames = null;

    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * Doctrine ORM uses __CG__ (Common Gateway) marker for proxy classes.
     * Note: With PHP 8.4+, Doctrine uses native lazy objects instead.
     */
    public static function getRealClassName(object|string $subject): string
    {
        $subject = \is_object($subject) ? $subject::class : $subject;

        // Fast path: with PHP 8.4+ native lazy objects, __CG__ proxies are never generated.
        // str_contains is significantly faster than mb_strrpos for the common no-proxy case.
        // __CG__: Doctrine Proxy Marker (Doctrine\Persistence\Proxy::MARKER)
        if (!str_contains($subject, '__CG__')) {
            return $subject;
        }

        $position = mb_strrpos($subject, '\__CG__\\');
        if (false === $position) {
            return $subject;
        }

        return mb_substr($subject, $position + 8);
    }

    public static function setPrimaryKey(Table $table, string $columnName): void
    {
        /** @var non-empty-string $columnName */
        // @phpstan-ignore method.deprecated (mutable schema of an ORM not using the schema editor API)
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
     *
     * @throws Exception
     */
    public static function jsonTypes(Connection $connection): array
    {
        $provider = $connection->getConfiguration()->getTypeProvider();
        self::$jsonTypes ??= new \WeakMap();

        return self::$jsonTypes[$provider] ??= [$provider->get(Types::JSON), $provider->get(Types::JSONB)];
    }

    /**
     * Resolves a DBAL type by name through the type provider of the connection.
     *
     * @throws Exception
     */
    public static function getType(Connection $connection, string $name): Type
    {
        return $connection->getConfiguration()->getTypeProvider()->get($name);
    }

    /**
     * Returns the name of the first type registered with the class of the given type instance in the type
     * provider of the connection, or false when there is none. Lookups are cached per type provider.
     */
    public static function getTypeName(Connection $connection, Type $type): false|string
    {
        $provider = $connection->getConfiguration()->getTypeProvider();
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
