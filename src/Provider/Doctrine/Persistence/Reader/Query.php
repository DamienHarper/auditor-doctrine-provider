<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\FilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\PlatformAwareFilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\QueryAwareFilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RootFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\QueryTest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Pages of transactions ending within the first WALK_MAX_TRANSACTIONS are read by walking the created_at index
     * (walkTransactions()), the deeper ones by grouping the table. Measured with 300,000 entries in 75,000 transactions,
     * pages of 50: page 1 in 1 to 2 ms instead of 100 to 800, page 100 in 20 to 40 ms; the last page would take 250 to
     * 450 ms, more than grouping on PostgreSQL.
     */
    private const int WALK_MAX_TRANSACTIONS = 10_000;

    /**
     * Entries a walk reads at most before it gives up for a grouped read (pages of very large transactions).
     */
    private const int WALK_MAX_ROWS = 50_000;

    private const int WALK_FIRST_BATCH = 500;

    private const int WALK_MAX_BATCH = 20_000;

    private array $filters = [];

    private array $orderBy = [];

    /**
     * The root filter of the history this query reads (Reader::createRootQuery()): the path an entry was indexed
     * under is read from it, whatever the other root filters (a read scope may add some).
     */
    private ?RootFilter $historyRoot = null;

    /**
     * Whether a null transaction id sorts as an empty string, like the cursors of RootQuery (by default, PostgreSQL
     * sorts nulls first in descending order and MySQL last).
     */
    private bool $nullTransactionIdAsEmpty = false;

    private int $offset = 0;

    private int $limit = 0;

    private readonly \DateTimeZone $timezone;

    private ?string $rootIndexTable = null;

    /**
     * Columns of the audit table, read from the schema once.
     *
     * @var null|list<string>
     */
    private ?array $columns = null;

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

    /**
     * Returns a page of the transactions (actions) the query reads, most recent first: the filters of the query apply,
     * its order and limit do not.
     *
     * @return list<TransactionSummary>
     */
    public function transactions(int $limit, int $offset = 0): array
    {
        return self::transactionsOf([$this], $limit, $offset);
    }

    /**
     * Number of transactions (actions) the query reads.
     */
    public function countTransactions(): int
    {
        return self::countTransactionsOf([$this]);
    }

    /**
     * Returns a page of the transactions read by several queries, each on its own audit table, most recent first: a
     * transaction that wrote in several tables is one transaction, dated by its most recent entry.
     *
     * Transactions are ordered by the date of their most recent entry, then by transaction id (both descending); the
     * entries without transaction id come after the others of the same instant. The queries of one connection are
     * read in one statement; with several connections, each one is read for $offset + $limit transactions.
     *
     * @param list<Query> $queries
     *
     * @return list<TransactionSummary>
     */
    public static function transactionsOf(array $queries, int $limit, int $offset = 0): array
    {
        if (1 > $limit) {
            throw new InvalidArgumentException('The page size must be at least 1.');
        }

        if (0 > $offset) {
            throw new InvalidArgumentException('Offset cannot be negative.');
        }

        $groups = self::byConnection($queries);
        if ([] === $groups) {
            return [];
        }

        if (1 === \count($groups)) {
            return self::readTransactions($groups[0], $limit, $offset);
        }

        /** @var array<string, TransactionSummary> $summaries */
        $summaries = [];
        foreach ($groups as $group) {
            foreach (self::readTransactions($group, $offset + $limit, 0) as $summary) {
                $known = $summaries[$summary->key()] ?? null;
                $summaries[$summary->key()] = null === $known ? $summary : new TransactionSummary(
                    $summary->transactionId,
                    $summary->entryId,
                    $summary->table,
                    max($known->createdAt, $summary->createdAt),
                    $known->entries + $summary->entries,
                );
            }
        }

        $summaries = array_values($summaries);
        usort($summaries, static fn (TransactionSummary $a, TransactionSummary $b): int => self::transactionSortKey($b) <=> self::transactionSortKey($a));

        return \array_slice($summaries, $offset, $limit);
    }

    /**
     * Number of transactions read by several queries, each on its own audit table: a transaction that wrote in several
     * tables of one connection is counted once (one that wrote in the audit tables of two connections, twice).
     *
     * @param list<Query> $queries
     */
    public static function countTransactionsOf(array $queries): int
    {
        $count = 0;
        foreach (self::byConnection($queries) as $group) {
            if (1 === \count($group)) {
                $column = $group[0]->transactionColumn();
                $result = $group[0]->buildTransactionQueryBuilder()
                    ->select(\sprintf('COUNT(DISTINCT %1$s) + SUM(CASE WHEN %1$s IS NULL THEN 1 ELSE 0 END)', $column))
                    ->executeQuery()
                    ->fetchOne()
                ;
            } else {
                [$sql, $params, $types] = self::union($group, static fn (Query $query): QueryBuilder => $query->buildTransactionQueryBuilder()->select($query->transactionSelect())->distinct(), 'UNION');
                $result = $group[0]->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM (%s) u', $sql), $params, $types);
            }

            $count += is_numeric($result) ? (int) $result : 0;
        }

        return $count;
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

    /**
     * Adds the root filter of the history the query reads.
     *
     * @internal
     */
    public function setHistoryRoot(RootFilter $filter): self
    {
        $this->historyRoot = $filter;

        return $this->addFilter($filter);
    }

    /**
     * Sorts a null transaction id as an empty string, on every platform.
     *
     * @internal
     */
    public function sortNullTransactionIdAsEmpty(): self
    {
        $this->nullTransactionIdAsEmpty = true;

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

    /**
     * Column of the transaction ids of the audit table (Query::TRANSACTION_ID), or Query::LEGACY_TRANSACTION_HASH in a
     * table not migrated to schema v2: the filter to read the entries of transactions.
     */
    public function getTransactionColumn(): string
    {
        return \in_array(self::TRANSACTION_ID, $this->columns(), true) ? self::TRANSACTION_ID : self::LEGACY_TRANSACTION_HASH;
    }

    /**
     * Entries of transactions read by the query (its filters kept), most recent first: in a table being migrated to
     * schema v2, the rows whose transaction_hash is not converted yet stand for their transaction by that hash, as in
     * Entry::fromArray().
     *
     * @param list<string> $transactionIds ids of TransactionSummary
     * @param int          $limit          entries read at most, 0 for no limit
     *
     * @return list<Entry>
     */
    public function transactionEntries(array $transactionIds, int $limit = 0): array
    {
        if ([] === $transactionIds) {
            return [];
        }

        $query = (clone $this)
            ->resetOrderBy()
            ->addOrderBy(self::CREATED_AT, 'DESC')
            ->addOrderBy(self::ID, 'DESC')
            ->limit($limit)
        ;
        $entries = (clone $query)->addFilter(new SimpleFilter($this->getTransactionColumn(), $transactionIds))->execute();
        if (!$this->isBeingMigrated()) {
            return $entries;
        }

        $legacy = (clone $query)
            ->addFilter(new SimpleFilter(self::LEGACY_TRANSACTION_HASH, $transactionIds))
            ->addFilter(new NullFilter(self::TRANSACTION_ID))
            ->execute()
        ;
        $entries = [...$entries, ...$legacy];
        usort($entries, static fn (Entry $a, Entry $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return 0 < $limit ? \array_slice($entries, 0, $limit) : $entries;
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
        if ($withRootPath && $this->historyRoot instanceof RootFilter) {
            $data = $this->historyRoot->getRootPathSQL($this);
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
            $queryBuilder->addOrderBy(self::TRANSACTION_ID === $field && $this->nullTransactionIdAsEmpty ? "COALESCE(transaction_id, '')" : $field, $direction);
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

    /**
     * Groups queries by connection, in the order of their first query.
     *
     * @param list<Query> $queries
     *
     * @return list<non-empty-list<Query>>
     */
    private static function byConnection(array $queries): array
    {
        $groups = [];
        foreach ($queries as $query) {
            $groups[spl_object_id($query->connection)][] = $query;
        }

        return array_values($groups);
    }

    /**
     * Reads a page of the transactions of queries sharing a connection.
     *
     * @param non-empty-list<Query> $group
     *
     * @return list<TransactionSummary>
     */
    private static function readTransactions(array $group, int $limit, int $offset): array
    {
        $first = $group[0];
        if (1 === \count($group) && !$first->isBeingMigrated() && $offset + $limit <= self::WALK_MAX_TRANSACTIONS) {
            $walked = $first->walkTransactions($limit, $offset);
            if (null !== $walked) {
                return $walked;
            }
        }

        if (1 === \count($group) && !$first->isBeingMigrated()) {
            $column = $first->transactionColumn();
            $rows = $first->buildTransactionQueryBuilder()
                ->select($first->transactionSelect().', MAX(at.created_at) AS last_at, COUNT(*) AS entries')
                ->groupBy($column, $first->entryIdExpression())
                ->orderBy('MAX(at.created_at)', 'DESC')
                ->addOrderBy(\sprintf("COALESCE(%s, '')", $column), 'DESC')
                // the alias: MySQL does not match an expression on a grouped expression (only entries without
                // transaction id have one, so where nulls sort does not matter)
                ->addOrderBy('entry_id', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->executeQuery()
                ->fetchAllAssociative()
            ;
        } else {
            [$sql, $params, $types] = self::union($group, static fn (Query $query): QueryBuilder => $query->buildTransactionQueryBuilder()
                ->select($query->transactionSelect().', MAX(at.created_at) AS last_at, COUNT(*) AS entries')
                // entry_table grouped too: MySQL does not see that it depends on a grouped COALESCE() (table being migrated)
                ->groupBy($query->transactionColumn(), $query->entryIdExpression(), $query->entryTableExpression()), 'UNION ALL');
            $sql = $first->connection->getDatabasePlatform()->modifyLimitQuery(
                \sprintf(
                    "SELECT tx, entry_id, entry_table, MAX(last_at) AS last_at, SUM(entries) AS entries FROM (%s) u GROUP BY tx, entry_id, entry_table ORDER BY MAX(last_at) DESC, COALESCE(tx, '') DESC, COALESCE(entry_table, '') DESC, COALESCE(entry_id, 0) DESC",
                    $sql,
                ),
                $limit,
                $offset,
            );
            $rows = $first->connection->fetchAllAssociative($sql, $params, $types);
        }

        return array_map(static function (array $row) use ($first): TransactionSummary {
            $lastAt = $row['last_at'];
            \assert(\is_string($lastAt));

            return new TransactionSummary(
                \is_scalar($row['tx'] ?? null) ? (string) $row['tx'] : null,
                is_numeric($row['entry_id'] ?? null) ? (int) $row['entry_id'] : null,
                \is_string($row['entry_table'] ?? null) ? $row['entry_table'] : null,
                new \DateTimeImmutable($lastAt, $first->timezone),
                is_numeric($row['entries']) ? (int) $row['entries'] : 0,
            );
        }, $rows);
    }

    /**
     * Reads a page of the transactions of the query by walking its entries most recent first, on the created_at index,
     * until $offset + $limit transactions are found: the first pages no longer group the whole table. Null when the walk
     * reads more than WALK_MAX_ROWS entries (very large transactions): the caller groups the table instead.
     *
     * The walk reads every entry of the instant where it stops, so that the transactions of that instant keep the order
     * of a grouped read (date of their most recent entry, then transaction id, both descending).
     *
     * @return null|list<TransactionSummary>
     */
    private function walkTransactions(int $limit, int $offset): ?array
    {
        $column = $this->transactionColumn();

        /** @var array<string, array{?string, ?int, string}> $found transaction id or entry id => [transaction id, entry id, date] */
        $found = [];
        $boundary = null;
        $last = null;
        $read = 0;
        $batch = self::WALK_FIRST_BATCH;
        while (true) {
            $queryBuilder = $this->buildTransactionQueryBuilder()
                ->select(\sprintf('at.id, %s AS tx, at.created_at', $column))
                ->orderBy('at.created_at', 'DESC')
                ->addOrderBy('at.id', 'DESC')
                ->setMaxResults($batch)
            ;
            if (null !== $last) {
                $queryBuilder
                    ->andWhere('(at.created_at < :walk_at OR (at.created_at = :walk_at AND at.id < :walk_id))')
                    ->setParameter('walk_at', $last[0])
                    ->setParameter('walk_id', $last[1], ParameterType::INTEGER)
                ;
            }

            $rows = $queryBuilder->executeQuery()->fetchAllNumeric();
            foreach ($rows as $row) {
                $createdAt = $row[2];
                \assert(\is_string($createdAt));
                if (null !== $boundary && $createdAt !== $boundary) {
                    break 2;
                }

                $transactionId = \is_scalar($row[1]) ? (string) $row[1] : null;
                $entryId = is_numeric($row[0]) ? (int) $row[0] : 0;
                $found[null === $transactionId ? '#'.$entryId : '='.$transactionId] ??= [$transactionId, null === $transactionId ? $entryId : null, $createdAt];
                if (null === $boundary && \count($found) >= $offset + $limit) {
                    $boundary = $createdAt;
                }

                $last = [$createdAt, $entryId];
            }

            $read += \count($rows);
            if (\count($rows) < $batch) {
                break;
            }

            if ($read >= self::WALK_MAX_ROWS) {
                return null;
            }

            $batch = min($batch * 2, self::WALK_MAX_BATCH);
        }

        $page = array_map(fn (array $transaction): array => [$transaction[0], $transaction[1], new \DateTimeImmutable($transaction[2], $this->timezone)], array_values($found));
        usort($page, static fn (array $a, array $b): int => [$b[2], $b[0] ?? '', $b[1] ?? 0] <=> [$a[2], $a[0] ?? '', $a[1] ?? 0]);
        $page = \array_slice($page, $offset, $limit);

        // number of entries of each transaction, with the filters of the query, on the transaction index
        $transactionIds = array_values(array_filter(array_column($page, 0), \is_string(...)));
        $entries = [] === $transactionIds ? [] : $this->buildTransactionQueryBuilder()
            ->select(\sprintf('%s AS tx, COUNT(*) AS entries', $column))
            ->andWhere(\sprintf('%s IN (:walk_transactions)', $column))
            ->setParameter('walk_transactions', $transactionIds, ArrayParameterType::STRING)
            ->groupBy($column)
            ->executeQuery()
            ->fetchAllKeyValue()
        ;

        return array_map(fn (array $transaction): TransactionSummary => new TransactionSummary(
            $transaction[0],
            $transaction[1],
            null === $transaction[0] ? $this->table : null,
            $transaction[2],
            null === $transaction[0] ? 1 : (is_numeric($entries[$transaction[0]] ?? null) ? (int) $entries[$transaction[0]] : 0),
        ), $page);
    }

    /**
     * Joins the statements built for queries sharing a connection, each one with its own parameter names.
     *
     * @param non-empty-list<Query>         $group
     * @param callable(Query): QueryBuilder $build
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private static function union(array $group, callable $build, string $operator): array
    {
        $parts = [];
        $params = [];
        $types = [];
        foreach ($group as $index => $query) {
            $queryBuilder = $build($query);
            $sql = $queryBuilder->getSQL();
            $partTypes = $queryBuilder->getParameterTypes();
            foreach ($queryBuilder->getParameters() as $name => $value) {
                $renamed = \sprintf('%s_u%d', $name, $index);
                $sql = (string) preg_replace(\sprintf('/(?<![:\w]):%s(?!\w)/', preg_quote((string) $name, '/')), ':'.$renamed, $sql);
                $params[$renamed] = $value;
                if (isset($partTypes[$name])) {
                    $types[$renamed] = $partTypes[$name];
                }
            }

            $parts[] = $sql;
        }

        return [implode(' '.$operator.' ', $parts), $params, $types];
    }

    /**
     * @return array{\DateTimeImmutable, string, string, int}
     */
    private static function transactionSortKey(TransactionSummary $summary): array
    {
        return [$summary->createdAt, $summary->transactionId ?? '', $summary->table ?? '', $summary->entryId ?? 0];
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return $this->columns ??= SchemaHelper::getTableColumnNames($this->connection, $this->table);
    }

    /**
     * A table being migrated to schema v2 keeps transaction_hash next to transaction_id until its values are converted.
     */
    private function isBeingMigrated(): bool
    {
        return \in_array(self::TRANSACTION_ID, $this->columns(), true) && \in_array(self::LEGACY_TRANSACTION_HASH, $this->columns(), true);
    }

    /**
     * Expression of the transaction id of an entry: in a table being migrated, the hash of a row not converted yet.
     */
    private function transactionColumn(): string
    {
        return $this->isBeingMigrated()
            ? \sprintf('COALESCE(at.%s, at.%s)', self::TRANSACTION_ID, self::LEGACY_TRANSACTION_HASH)
            : 'at.'.$this->getTransactionColumn();
    }

    /**
     * Id of an entry without transaction id, which is a transaction of its own; null for the others.
     */
    private function entryIdExpression(): string
    {
        return \sprintf('CASE WHEN %s IS NULL THEN at.id END', $this->transactionColumn());
    }

    /**
     * Columns identifying a transaction: its id, or the id and table of an entry without transaction id.
     */
    private function transactionSelect(): string
    {
        $column = $this->transactionColumn();

        return \sprintf('%s AS tx, %s AS entry_id, %s AS entry_table', $column, $this->entryIdExpression(), $this->entryTableExpression());
    }

    /**
     * Table of an entry without transaction id; null for the others.
     */
    private function entryTableExpression(): string
    {
        return \sprintf('CASE WHEN %s IS NULL THEN %s END', $this->transactionColumn(), $this->connection->quote($this->table));
    }

    /**
     * Statement on the audit table with the filters of the query, without its order and limit.
     */
    private function buildTransactionQueryBuilder(): QueryBuilder
    {
        return $this->buildWhere($this->connection->createQueryBuilder()->from($this->table, 'at'));
    }

    private function checkFilter(string $filter): void
    {
        if (!\in_array($filter, $this->getSupportedFilters(), true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported "%s" filter, allowed filters: %s.', $filter, implode(', ', $this->getSupportedFilters())));
        }
    }
}
