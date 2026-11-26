<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter\ImpersonatorFilterTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Keeps the entries made while a user impersonated another one (switch user): those whose blame has an
 * `impersonator` key, or, given an id, those of that impersonator.
 *
 * Reads the blame JSON column of schema v2 tables: it needs a platform with native JSON functions.
 *
 * @see ImpersonatorFilterTest
 */
final readonly class ImpersonatorFilter implements PlatformAwareFilterInterface
{
    /**
     * @param null|string $impersonatorId identifier of the impersonator; null for any impersonator
     */
    public function __construct(private ?string $impersonatorId = null)
    {
        if ('' === $impersonatorId) {
            throw new InvalidArgumentException('The identifier of an impersonator cannot be empty.');
        }
    }

    public function getName(): string
    {
        return Query::BLAME;
    }

    public function getImpersonatorId(): ?string
    {
        return $this->impersonatorId;
    }

    #[\Deprecated(message: 'Use getSQLWithConnection() instead')]
    public function getSQL(): array
    {
        throw new InvalidArgumentException('ImpersonatorFilter requires a database connection. Use getSQLWithConnection() instead.');
    }

    public function getSQLWithConnection(Connection $connection): array
    {
        if (!JsonPlatformHelper::isJsonSearchSupported($connection)) {
            throw new InvalidArgumentException(\sprintf('Filtering on the impersonator requires native JSON support, which this %s version lacks.', JsonPlatformHelper::getPlatformName($connection)));
        }

        $platform = $connection->getDatabasePlatform();
        [$isObject, $id] = match (true) {
            $platform instanceof PostgreSQLPlatform => ["jsonb_typeof(CAST(at.blame AS jsonb) -> 'impersonator') = 'object'", "(CAST(at.blame AS jsonb) -> 'impersonator' ->> 'id')"],
            $platform instanceof AbstractMySQLPlatform => ["JSON_TYPE(JSON_EXTRACT(at.blame, '$.impersonator')) = 'OBJECT'", "JSON_UNQUOTE(JSON_EXTRACT(at.blame, '$.impersonator.id'))"],
            $platform instanceof SQLitePlatform => ["json_type(at.blame, '$.impersonator') = 'object'", "json_extract(at.blame, '$.impersonator.id')"],
            default => throw new InvalidArgumentException(\sprintf('Filtering on the impersonator is not supported on %s.', JsonPlatformHelper::getPlatformName($connection))),
        };

        if (null === $this->impersonatorId) {
            return ['sql' => '('.$isObject.')', 'params' => []];
        }

        // one parameter per identifier: several filters of a query do not overwrite each other's value
        $parameter = 'impersonator_'.bin2hex($this->impersonatorId);

        return ['sql' => \sprintf('(%s AND %s = :%s)', $isObject, $id, $parameter), 'params' => [$parameter => $this->impersonatorId]];
    }
}
