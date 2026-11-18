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
final class AuditInsertBuffer
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

    private const int MAX_ROWS = 500;

    /**
     * Rows per statement on a platform without a known limit of bound parameters.
     */
    private const int DEFAULT_MAX_ROWS = 99;

    /**
     * Buffered rows grouped by connection and audit table, in the order of their first row.
     *
     * @var array<string, array{Connection, string, list<list<mixed>>}>
     */
    private array $groups = [];

    private int $count = 0;

    private int $batchSize = 0;

    private ?\Throwable $failure = null;

    /**
     * Buffers a row of $auditTable (a table name as it appears in SQL, possibly schema-qualified and quoted),
     * whose values are ordered like COLUMNS, and writes every buffered row once $batchSize rows are buffered.
     *
     * @param list<mixed> $row
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
        $this->batchSize = $batchSize;

        if ($this->count >= $batchSize) {
            try {
                $this->write();
            } catch (\Throwable $throwable) {
                $this->failure = $throwable;
            }
        }
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

    public function isEmpty(): bool
    {
        return 0 === $this->count;
    }

    public function count(): int
    {
        return $this->count;
    }

    private function write(): void
    {
        $groups = $this->groups;
        $batchSize = $this->batchSize;
        $this->groups = [];
        $this->count = 0;

        foreach ($groups as [$connection, $auditTable, $rows]) {
            $platform = $connection->getDatabasePlatform();
            $maxRows = $this->maxRowsPerStatement($platform);
            if ($batchSize > 0) {
                $maxRows = min($maxRows, $batchSize);
            }

            $placeholders = $this->rowPlaceholders($platform);
            foreach (array_chunk($rows, $maxRows) as $chunk) {
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
