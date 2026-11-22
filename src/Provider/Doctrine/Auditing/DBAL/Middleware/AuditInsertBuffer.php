<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

use DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL\AuditInsertBufferTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Audit entries of the database transaction being committed, written with multi-row INSERT statements
 * (`batch_size` option).
 *
 * The audit entries are written by flush(), right before the COMMIT, or as soon as `batch_size` entries are
 * buffered. That early flush runs while an audit entry is persisted, where the auditor ignores exceptions: its
 * failure is kept and rethrown by the next flush(), so that the commit fails instead of losing audit entries.
 *
 * @internal
 *
 * @see AuditInsertBufferTest
 */
final class AuditInsertBuffer implements \Countable
{
    /**
     * Columns of an audit table written for each audit entry, in the order of the values of a buffered row.
     */
    public const array COLUMNS = [
        'schema_version',
        'type',
        'object_id',
        'discriminator',
        'transaction_id',
        'diffs',
        'extra_data',
        'blame_id',
        'blame',
        'created_at',
    ];

    /**
     * JSON columns: PostgreSQL requires an explicit ::jsonb cast of their placeholders (the driver otherwise
     * infers the placeholder type as text, causing a type mismatch).
     */
    public const array JSON_COLUMNS = ['diffs', 'extra_data', 'blame'];

    /**
     * Rows per statement on SQLite: 999 bound parameters at most before SQLite 3.32.
     */
    private const int SQLITE_MAX_ROWS = 99;

    /**
     * Rows per statement on MySQL, MariaDB and PostgreSQL: 5000 bound parameters, far below their limit of 65535.
     */
    private const int MAX_ROWS = 500;

    /**
     * Rows per statement on a platform without a known limit of bound parameters.
     */
    private const int DEFAULT_MAX_ROWS = 99;

    /**
     * Bytes of string values per statement, a row larger than that being written alone: MySQL and MariaDB reject
     * a statement larger than max_allowed_packet (as low as 1 MiB on older or tuned servers).
     */
    private const int MAX_BYTES = 1_024 * 1_024;

    /**
     * Buffered rows grouped by connection and audit table, in the order of their first row.
     *
     * @var array<string, array{Connection, string, list<list<mixed>>}>
     */
    private array $groups = [];

    private int $count = 0;

    private ?\Throwable $failure = null;

    /**
     * @param null|positive-int $batchSize rows buffered before they are written, instead of the `batch_size` option
     *                                     (e.g. the batch size of audit:snapshot)
     */
    public function __construct(public readonly ?int $batchSize = null) {}

    /**
     * Buffers a row of $auditTable (a table name as it appears in SQL, possibly schema-qualified and quoted),
     * whose values are ordered like COLUMNS, and writes every buffered row once $batchSize rows are buffered.
     *
     * @param list<mixed>  $row
     * @param positive-int $batchSize
     */
    public function add(Connection $connection, string $auditTable, array $row, int $batchSize): void
    {
        if ($this->failure instanceof \Throwable) {
            // the commit is going to fail: these rows will never be written
            return;
        }

        $key = spl_object_id($connection).'.'.$auditTable;
        $this->groups[$key] ??= [$connection, $auditTable, []];
        $this->groups[$key][2][] = $row;
        ++$this->count;

        if ($this->count >= $batchSize) {
            try {
                $this->write();
            } catch (\Throwable $throwable) {
                $this->fail($throwable);
            }
        }
    }

    /**
     * Memorises a failure to report by the next flush() (only the first one: it is the probable cause of the next
     * ones) and drops the buffered rows: the commit is going to fail, they will never be written.
     */
    public function fail(\Throwable $throwable): void
    {
        $this->failure ??= $throwable;
        $this->groups = [];
        $this->count = 0;
    }

    /**
     * Returns the memorised failure, if any, and forgets it.
     */
    public function takeFailure(): ?\Throwable
    {
        $failure = $this->failure;
        $this->failure = null;

        return $failure;
    }

    /**
     * Writes every buffered row. Rethrows the failure of an early flush, if any. The buffer is empty afterwards,
     * whether the rows could be written or not.
     */
    public function flush(): void
    {
        if ($this->failure instanceof \Throwable) {
            $failure = $this->failure;
            $this->discard();

            throw $failure;
        }

        $this->write();
    }

    /**
     * Drops every buffered row and the failure of an early flush, if any, without writing anything.
     */
    public function discard(): void
    {
        $this->groups = [];
        $this->count = 0;
        $this->failure = null;
    }

    /**
     * Number of buffered rows. A failure memorised by an early flush is not counted: an empty buffer may still have
     * a failure to report, flush() must always be called when the transaction is committed.
     */
    public function count(): int
    {
        return $this->count;
    }

    private function write(): void
    {
        $groups = $this->groups;
        $this->groups = [];
        $this->count = 0;

        foreach ($groups as [$connection, $auditTable, $rows]) {
            $platform = $connection->getDatabasePlatform();
            $maxRows = $this->maxRowsPerStatement($platform);

            $placeholders = $this->rowPlaceholders($platform);
            foreach ($this->chunks($rows, $maxRows) as $chunk) {
                $connection->executeStatement(
                    \sprintf(
                        'INSERT INTO %s (%s) VALUES %s',
                        $auditTable,
                        implode(', ', self::COLUMNS),
                        implode(', ', array_fill(0, \count($chunk), $placeholders))
                    ),
                    array_merge(...$chunk)
                );
            }
        }
    }

    /**
     * Splits the rows into chunks of at most $maxRows rows and MAX_BYTES bytes of string values.
     *
     * @param list<list<mixed>> $rows
     * @param positive-int      $maxRows
     *
     * @return iterable<non-empty-list<list<mixed>>>
     */
    private function chunks(array $rows, int $maxRows): iterable
    {
        $chunk = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $rowBytes = 0;
            foreach ($row as $value) {
                if (\is_string($value)) {
                    $rowBytes += mb_strlen($value, '8bit'); // bytes, not characters
                }
            }

            if ([] !== $chunk && (\count($chunk) >= $maxRows || $bytes + $rowBytes > self::MAX_BYTES)) {
                yield $chunk;

                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $row;
            $bytes += $rowBytes;
        }

        if ([] !== $chunk) {
            yield $chunk;
        }
    }

    /**
     * @return positive-int
     */
    private function maxRowsPerStatement(AbstractPlatform $platform): int
    {
        return match (true) {
            $platform instanceof SQLitePlatform => self::SQLITE_MAX_ROWS,
            $platform instanceof AbstractMySQLPlatform, $platform instanceof PostgreSQLPlatform => self::MAX_ROWS,
            default => self::DEFAULT_MAX_ROWS,
        };
    }

    private function rowPlaceholders(AbstractPlatform $platform): string
    {
        $jsonCast = $platform instanceof PostgreSQLPlatform ? '::jsonb' : '';

        return '('.implode(', ', array_map(
            static fn (string $column): string => \in_array($column, self::JSON_COLUMNS, true) ? '?'.$jsonCast : '?',
            self::COLUMNS
        )).')';
    }
}
