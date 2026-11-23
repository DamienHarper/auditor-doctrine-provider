<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\FilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\PlatformAwareFilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\QueryAwareFilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RootFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\QueryTest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

/**
 * @see QueryTest
 */
final class Query implements QueryInterface
{
    public const string TYPE = 'type';

    public const string CREATED_AT = 'created_at';

    public const string TRANSACTION_ID = 'transaction_id';

    #[\Deprecated(message: 'Use TRANSACTION_ID instead')]
    public const string TRANSACTION_HASH = 'transaction_id';

    /**
     * Legacy v1 column name — used to filter by transaction_hash on un-migrated tables.
     */
    public const string LEGACY_TRANSACTION_HASH = 'transaction_hash';

    public const string OBJECT_ID = 'object_id';

    public const string USER_ID = 'blame_id';

    public const string ID = 'id';

    public const string DISCRIMINATOR = 'discriminator';

    public const string JSON = 'json';

    /**
     * Name of the filters on the content of the diffs column (see ChangedFieldFilter).
     */
    public const string DIFFS = 'diffs';

    /**
     * Name of the filters on the audit root index (see RootFilter).
     */
    public const string ROOT = 'root';

    /**
     * Name of the filters on the content of the blame column (see BlameOriginFilter).
     */
    public const string BLAME = 'blame';

    /**
     * Name of the keyset pagination filters of the history of a root.
     */
    public const string CURSOR = 'cursor';

    private array $filters = [];

    private array $orderBy = [];

    private int $offset = 0;

    private int $limit = 0;

    private readonly \DateTimeZone $timezone;

    private ?string $rootIndexTable = null;

    public function __construct(private readonly string $table, private readonly Connection $connection, string $timezone)
    {
        $this->timezone = new \DateTimeZone($timezone);

        foreach ($this->getSupportedFilters() as $filterType) {
            $this->filters[$filterType] = [];
        }
    }

    /**
     * @return array<Entry>
     */
    public function execute(): array
    {
        $statement = $this->buildPageQueryBuilder()->executeQuery();

        $result = [];
        foreach ($statement->fetchAllAssociative() as $row) {
            \assert(\is_string($row['created_at']));
            $row['created_at'] = new \DateTimeImmutable($row['created_at'], $this->timezone);
            $result[] = Entry::fromArray($row);
        }

        return $result;
    }

    /**
     * Yields raw DB rows as associative arrays, one at a time, without loading the full
     * result set into memory. Suitable for streaming large exports via NDJSON, CSV, or JSON.
     *
     * The 'created_at' field is a raw string — callers must convert it before calling Entry::fromArray().
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(): \Generator
    {
        $statement = $this->buildQueryBuilder(true)->executeQuery();

        yield from $statement->iterateAssociative();
    }

    public function count(): int
    {
        $result = $this->buildQueryBuilder()
            ->select('COUNT(id)')
            ->resetOrderBy()
            ->setMaxResults(null)
            ->setFirstResult(0)
            ->executeQuery()
            ->fetchOne()
        ;

        return is_numeric($result) ? (int) $result : 0;
    }

    public function addFilter(FilterInterface $filter): self
    {
        $this->checkFilter($filter->getName());
        $this->filters[$filter->getName()][] = $filter;

        return $this;
    }

    public function addOrderBy(string $field, string $direction = 'DESC'): self
    {
        $this->checkFilter($field);

        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid sort direction, allowed value: ASC, DESC');
        }

        $this->orderBy[$field] = $direction;

        return $this;
    }

    public function resetOrderBy(): self
    {
        return $this->resetQueryPart('orderBy');
    }

    public function resetQueryPart(string $part): self
    {
        $allowedParts = ['orderBy', 'limit', 'filters'];

        if (!\in_array($part, $allowedParts, true)) {
            throw new InvalidArgumentException(\sprintf('Invalid query part "%s", allowed parts: %s.', $part, implode(', ', $allowedParts)));
        }

        match ($part) {
            'orderBy' => $this->orderBy = [],
            'limit' => [$this->limit, $this->offset] = [0, 0],
            'filters' => $this->filters = array_fill_keys($this->getSupportedFilters(), []),
        };

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if (0 > $limit) {
            throw new InvalidArgumentException('Limit cannot be negative.');
        }

        if (0 > $offset) {
            throw new InvalidArgumentException('Offset cannot be negative.');
        }

        $this->limit = $limit;
        $this->offset = $offset;

        return $this;
    }

    /**
     * Audit table read by the query, as it appears in SQL.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * Sets the audit root index table that root filters read.
     */
    public function setRootIndexTable(string $rootIndexTable): self
    {
        $this->rootIndexTable = $rootIndexTable;

        return $this;
    }

    public function getRootIndexTable(): string
    {
        return $this->rootIndexTable ?? throw new InvalidArgumentException('The audit root index table of the query is not set.');
    }

    public function getSupportedFilters(): array
    {
        return [self::ROOT, self::BLAME, self::CURSOR, self::ID, self::TYPE, self::OBJECT_ID, self::DISCRIMINATOR, self::TRANSACTION_ID, self::USER_ID, self::CREATED_AT, self::JSON, self::DIFFS, self::LEGACY_TRANSACTION_HASH];
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * @return array<int>
     */
    public function getLimit(): array
    {
        return [$this->limit, $this->offset];
    }

    /**
     * @param bool $withRootPath selects the path entries read through a root filter were indexed under
     */
    private function buildQueryBuilder(bool $withRootPath = false): QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->table, 'at')
        ;

        // entries read through a root filter tell the path they were indexed under
        $rootFilter = $this->filters[self::ROOT][0] ?? null;
        if ($withRootPath && $rootFilter instanceof RootFilter) {
            $data = $rootFilter->getRootPathSQL($this);
            $queryBuilder->select('at.*', $data['sql'].' AS root_path');
            foreach ($data['params'] as $name => $value) {
                if (\is_array($value)) {
                    $queryBuilder->setParameter($name, $value, ArrayParameterType::STRING);
                } else {
                    $queryBuilder->setParameter($name, $value);
                }
            }
        }

        // build WHERE clause(s)
        $queryBuilder = $this->buildWhere($queryBuilder);

        // build ORDER BY part
        $queryBuilder = $this->buildOrderBy($queryBuilder);

        // build LIMIT part
        return $this->buildLimit($queryBuilder);
    }

    /**
     * Builds the query of execute(): a deep page of an unfiltered query is read with a deferred join on MySQL and
     * MariaDB, which skip the first rows on the created_at index alone instead of reading them whole (measured on
     * MySQL 8.4 with 300,000 rows: 605 ms -> 18 ms at offset 100,000). PostgreSQL gains nothing from it.
     */
    private function buildPageQueryBuilder(): QueryBuilder
    {
        if (0 === $this->offset || $this->hasFilters() || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return $this->buildQueryBuilder(true);
        }

        $page = $this->buildQueryBuilder()->select('id');
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('at.*')
            ->from($this->table, 'at')
            ->innerJoin('at', '('.$page->getSQL().')', 'page', 'at.id = page.id')
        ;

        foreach ($this->orderBy as $field => $direction) {
            $queryBuilder->addOrderBy('at.'.$field, $direction);
        }

        return $queryBuilder;
    }

    private function hasFilters(): bool
    {
        return array_any($this->filters, static fn (array $filters): bool => [] !== $filters);
    }

    private function groupFilters(array $filters): array
    {
        $grouped = [];

        foreach ($filters as $filter) {
            $grouped[$filter::class] ??= [];

            $grouped[$filter::class][] = $filter;
        }

        return $grouped;
    }

    private function mergeSimpleFilters(array $filters): SimpleFilter
    {
        if ([] === $filters) {
            throw new InvalidArgumentException('$filters cannot be empty.');
        }

        $merged = [];
        $name = null;

        foreach ($filters as $filter) {
            $name ??= $filter->getName();

            if (\is_array($filter->getValue())) {
                $merged = array_merge($merged, $filter->getValue());
            } else {
                $merged[] = $filter->getValue();
            }
        }

        return new SimpleFilter($name, $merged);
    }

    private function buildWhere(QueryBuilder $queryBuilder): QueryBuilder
    {
        foreach ($this->filters as $rawFilters) {
            if (0 === (is_countable($rawFilters) ? \count($rawFilters) : 0)) {
                continue;
            }

            // group filters by class
            $grouped = $this->groupFilters($rawFilters);

            foreach ($grouped as $class => $filters) {
                switch ($class) {
                    case SimpleFilter::class:
                        $filters = [$this->mergeSimpleFilters($filters)];

                        break;

                    case RangeFilter::class:
                    case DateRangeFilter::class:
                        break;
                }

                foreach ($filters as $filter) {
                    // Use query-aware or platform-aware method if available
                    if ($filter instanceof QueryAwareFilterInterface) {
                        $data = $filter->getSQLForQuery($this);
                    } elseif ($filter instanceof PlatformAwareFilterInterface) {
                        $data = $filter->getSQLWithConnection($this->connection);
                    } else {
                        $data = $filter->getSQL();
                    }

                    $queryBuilder->andWhere($data['sql']);

                    foreach ($data['params'] as $name => $value) {
                        if (\is_array($value)) {
                            $queryBuilder->setParameter($name, $value, ArrayParameterType::STRING);
                        } else {
                            $queryBuilder->setParameter($name, $value);
                        }
                    }
                }
            }
        }

        return $queryBuilder;
    }

    private function buildOrderBy(QueryBuilder $queryBuilder): QueryBuilder
    {
        foreach ($this->orderBy as $field => $direction) {
            $queryBuilder->addOrderBy($field, $direction);
        }

        return $queryBuilder;
    }

    private function buildLimit(QueryBuilder $queryBuilder): QueryBuilder
    {
        if (0 < $this->limit) {
            $queryBuilder->setMaxResults($this->limit);
        }

        if (0 < $this->offset) {
            $queryBuilder->setFirstResult($this->offset);
        }

        return $queryBuilder;
    }

    private function checkFilter(string $filter): void
    {
        if (!\in_array($filter, $this->getSupportedFilters(), true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported "%s" filter, allowed filters: %s.', $filter, implode(', ', $this->getSupportedFilters())));
        }
    }
}
