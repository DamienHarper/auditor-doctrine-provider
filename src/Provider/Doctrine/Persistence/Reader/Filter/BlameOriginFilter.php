<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter\BlameOriginFilterTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Keeps the entries made from some origins (see BlameOrigin): a web request of an authenticated user, a console
 * command, a message handler or anything else.
 *
 * Reads the blame JSON column of schema v2 tables: it needs a platform with native JSON functions.
 *
 * @see BlameOriginFilterTest
 */
final readonly class BlameOriginFilter implements PlatformAwareFilterInterface
{
    /**
     * @var list<BlameOrigin>
     */
    private array $origins;

    /**
     * @param BlameOrigin|list<BlameOrigin|string>|string $origins
     */
    public function __construct(array|BlameOrigin|string $origins)
    {
        $list = [];
        foreach (\is_array($origins) ? $origins : [$origins] as $origin) {
            $list[] = $origin instanceof BlameOrigin ? $origin : (BlameOrigin::tryFrom($origin) ?? throw new InvalidArgumentException(\sprintf('Invalid blame origin "%s", allowed values: %s.', $origin, implode(', ', array_map(static fn (BlameOrigin $case): string => $case->value, BlameOrigin::cases())))));
        }

        if ([] === $list) {
            throw new InvalidArgumentException('A blame origin filter needs at least one origin.');
        }

        $this->origins = array_values(array_unique($list, SORT_REGULAR));
    }

    public function getName(): string
    {
        return Query::BLAME;
    }

    /**
     * @return list<BlameOrigin>
     */
    public function getOrigins(): array
    {
        return $this->origins;
    }

    #[\Deprecated(message: 'Use getSQLWithConnection() instead')]
    public function getSQL(): array
    {
        throw new InvalidArgumentException('BlameOriginFilter requires a database connection. Use getSQLWithConnection() instead.');
    }

    public function getSQLWithConnection(Connection $connection): array
    {
        if (!JsonPlatformHelper::isJsonSearchSupported($connection)) {
            throw new InvalidArgumentException(\sprintf('Filtering on the blame origin requires native JSON support, which this %s version lacks.', JsonPlatformHelper::getPlatformName($connection)));
        }

        $platform = $connection->getDatabasePlatform();
        [$viaType, $firewall] = match (true) {
            $platform instanceof PostgreSQLPlatform => ["(CAST(at.blame AS jsonb) -> 'via' ->> 'type')", "(CAST(at.blame AS jsonb) ->> 'user_firewall')"],
            // JSON_UNQUOTE() turns a JSON null into the string "null" (JSON_VALUE() needs MySQL 8.0.21)
            $platform instanceof AbstractMySQLPlatform => ["NULLIF(JSON_UNQUOTE(JSON_EXTRACT(at.blame, '$.via.type')), 'null')", "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(at.blame, '$.user_firewall')), 'null')"],
            $platform instanceof SQLitePlatform => ["json_extract(at.blame, '$.via.type')", "json_extract(at.blame, '$.user_firewall')"],
            default => throw new InvalidArgumentException(\sprintf('Filtering on the blame origin is not supported on %s.', JsonPlatformHelper::getPlatformName($connection))),
        };

        $console = BlameOrigin::CONSOLE_CONTEXT;
        $messenger = BlameOrigin::MESSENGER_CONTEXT;
        $noContext = \sprintf("(%1\$s IS NULL OR %1\$s NOT IN ('%2\$s', '%3\$s'))", $viaType, $console, $messenger);
        $hasFirewall = \sprintf("(%1\$s IS NOT NULL AND %1\$s <> '')", $firewall);

        $conditions = [];
        foreach ($this->origins as $origin) {
            $conditions[] = match ($origin) {
                BlameOrigin::Console => \sprintf("%s = '%s'", $viaType, $console),
                BlameOrigin::Messenger => \sprintf("%s = '%s'", $viaType, $messenger),
                BlameOrigin::Http => \sprintf('(%s AND %s)', $noContext, $hasFirewall),
                BlameOrigin::System => \sprintf('(%s AND NOT %s)', $noContext, $hasFirewall),
            };
        }

        return ['sql' => '('.implode(' OR ', $conditions).')', 'params' => []];
    }
}
