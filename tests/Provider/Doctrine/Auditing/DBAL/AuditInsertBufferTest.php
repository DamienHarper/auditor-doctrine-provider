<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditInsertBuffer;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class AuditInsertBufferTest extends TestCase
{
    private StatementLoggerMiddleware $logger;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->logger = new StatementLoggerMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$this->logger]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->createAuditTable('author_audit');
        $this->createAuditTable('post_audit');
        $this->logger->clear();
    }

    public function testRowsAreWrittenByASingleStatementOnFlush(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 100);
        $buffer->add($this->connection, 'author_audit', $this->row('2'), 100);
        $buffer->add($this->connection, 'author_audit', $this->row('3'), 100);

        $this->assertSame(3, $buffer->count());
        $this->assertSame([], $this->logger->statementsStartingWith('INSERT'), 'Nothing is written before the flush.');

        $buffer->flush();

        $this->assertCount(1, $this->logger->statementsStartingWith('INSERT'));
        $this->assertSame(['1', '2', '3'], $this->objectIds('author_audit'), 'Rows are written in the order they were added.');
    }

    public function testEveryColumnIsWrittenAsGiven(): void
    {
        $buffer = new AuditInsertBuffer();
        $row = $this->row('42');
        $buffer->add($this->connection, 'author_audit', $row, 100);
        $buffer->flush();

        $written = $this->connection->fetchAssociative('SELECT '.implode(', ', AuditInsertBuffer::COLUMNS).' FROM author_audit');
        $this->assertIsArray($written);
        $this->assertSame(array_combine(AuditInsertBuffer::COLUMNS, $row), $written);
    }

    public function testRowsAreSplitIntoStatementsOfAtMostThePlatformLimit(): void
    {
        $buffer = new AuditInsertBuffer();
        for ($i = 1; $i <= 250; ++$i) {
            $buffer->add($this->connection, 'author_audit', $this->row((string) $i), 1_000);
        }

        $buffer->flush();

        $this->assertCount(3, $this->logger->statementsStartingWith('INSERT'), 'SQLite: at most 99 rows per statement.');
        $this->assertSame(250, $this->countRows('author_audit'));
        $this->assertSame(array_map(strval(...), range(1, 250)), $this->objectIds('author_audit'));
    }

    public function testEachAuditTableIsWrittenByItsOwnStatementInTheOrderOfTheirFirstRow(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'post_audit', $this->row('1'), 100);
        $buffer->add($this->connection, 'author_audit', $this->row('2'), 100);
        $buffer->add($this->connection, 'post_audit', $this->row('3'), 100);

        $buffer->flush();

        $inserts = $this->logger->statementsStartingWith('INSERT');
        $this->assertCount(2, $inserts);
        $this->assertStringStartsWith('INSERT INTO post_audit ', $inserts[0]);
        $this->assertStringStartsWith('INSERT INTO author_audit ', $inserts[1]);
        $this->assertSame(['1', '3'], $this->objectIds('post_audit'));
        $this->assertSame(['2'], $this->objectIds('author_audit'));
    }

    public function testRowsOfEachConnectionAreWrittenOnThatConnection(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createAuditTable('author_audit', $other);

        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 100);
        $buffer->add($other, 'author_audit', $this->row('2'), 100);
        $buffer->flush();

        $this->assertSame(['1'], $this->objectIds('author_audit'));
        $this->assertSame(['2'], $this->objectIds('author_audit', $other));
    }

    public function testFlushClearsTheBuffer(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 100);
        $buffer->flush();

        $this->assertCount(0, $buffer);
        $this->assertSame(0, $buffer->count());

        $this->logger->clear();
        $buffer->flush();

        $this->assertSame([], $this->logger->statementsStartingWith('INSERT'), 'A second flush writes nothing.');
        $this->assertSame(1, $this->countRows('author_audit'));
    }

    public function testFlushOfAnEmptyBufferWritesNothing(): void
    {
        new AuditInsertBuffer()->flush();

        $this->assertSame([], $this->logger->statementsStartingWith('INSERT'));
    }

    public function testDiscardWritesNothing(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 100);
        $buffer->discard();

        $this->assertCount(0, $buffer);

        $buffer->flush();

        $this->assertSame([], $this->logger->statementsStartingWith('INSERT'));
        $this->assertSame(0, $this->countRows('author_audit'));
    }

    public function testReachingTheBatchSizeFlushesEarly(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 3);
        $buffer->add($this->connection, 'post_audit', $this->row('2'), 3);
        $this->assertSame(0, $this->countRows('author_audit') + $this->countRows('post_audit'));

        $buffer->add($this->connection, 'author_audit', $this->row('3'), 3);

        $this->assertCount(0, $buffer, 'The batch size counts the rows of every audit table.');
        $this->assertSame(['1', '3'], $this->objectIds('author_audit'));
        $this->assertSame(['2'], $this->objectIds('post_audit'));

        $buffer->add($this->connection, 'author_audit', $this->row('4'), 3);
        $buffer->flush();

        $this->assertSame(['1', '3', '4'], $this->objectIds('author_audit'));
        $this->assertCount(3, $this->logger->statementsStartingWith('INSERT'));
    }

    public function testAFailingFlushPropagatesAndClearsTheBuffer(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'missing_audit', $this->row('1'), 100);

        try {
            $buffer->flush();
            $this->fail('The flush must fail.');
        } catch (TableNotFoundException) {
        }

        $this->assertCount(0, $buffer);
        $buffer->flush();
    }

    public function testAFailingEarlyFlushIsRethrownByTheNextFlush(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'author_audit', $this->row('1'), 2);

        // the early flush runs while the audit entry is persisted, where the auditor ignores exceptions
        $buffer->add($this->connection, 'missing_audit', $this->row('2'), 2);

        $this->assertCount(0, $buffer);

        $buffer->add($this->connection, 'author_audit', $this->row('3'), 2);
        $buffer->add($this->connection, 'author_audit', $this->row('4'), 2);

        $this->assertCount(0, $buffer, 'Rows added after a failure are ignored.');

        try {
            $buffer->flush();
            $this->fail('The flush must rethrow the failure of the early flush.');
        } catch (TableNotFoundException) {
        }

        $this->assertSame(['1'], $this->objectIds('author_audit'), 'Rows added after the failure are not written.');

        // the failure is reported once: the buffer can be used again
        $buffer->add($this->connection, 'author_audit', $this->row('5'), 100);
        $buffer->flush();

        $this->assertSame(['1', '5'], $this->objectIds('author_audit'));
    }

    public function testDiscardForgetsAFailingEarlyFlush(): void
    {
        $buffer = new AuditInsertBuffer();
        $buffer->add($this->connection, 'missing_audit', $this->row('1'), 1);
        $buffer->discard();

        $buffer->flush();

        $buffer->add($this->connection, 'author_audit', $this->row('2'), 100);
        $buffer->flush();

        $this->assertSame(['2'], $this->objectIds('author_audit'));
    }

    #[DataProvider('provideStatementsFollowThePlatformCases')]
    public function testStatementsFollowThePlatform(AbstractPlatform $platform, int $maxRows, string $jsonPlaceholder): void
    {
        $statements = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 1;
        });

        $buffer = new AuditInsertBuffer();
        for ($i = 1; $i <= $maxRows + 1; ++$i) {
            $buffer->add($connection, 'author_audit', $this->row((string) $i), 10_000);
        }

        $buffer->flush();

        $this->assertCount(2, $statements);
        $columns = \count(AuditInsertBuffer::COLUMNS);
        $this->assertCount($maxRows * $columns, $statements[0][1]);
        $this->assertCount($columns, $statements[1][1]);

        $row = \sprintf('(?, ?, ?, ?, ?, %1$s, %1$s, ?, %1$s, ?)', $jsonPlaceholder);
        $this->assertSame(
            'INSERT INTO author_audit (schema_version, type, object_id, discriminator, transaction_id, diffs, extra_data, blame_id, blame, created_at) VALUES '.$row,
            $statements[1][0],
        );
        $this->assertSame($maxRows + 1, mb_substr_count($statements[0][0], '('), 'The column list and one group of placeholders per row.');
    }

    /**
     * @return iterable<string, array{AbstractPlatform, int, string}>
     */
    public static function provideStatementsFollowThePlatformCases(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 500, '?::jsonb'];

        yield 'MySQL' => [new MySQLPlatform(), 500, '?'];

        yield 'MariaDB' => [new MariaDBPlatform(), 500, '?'];

        yield 'other platform' => [new SQLServerPlatform(), 99, '?'];
    }

    /**
     * @return list<mixed>
     */
    private function row(string $objectId): array
    {
        return [
            2,
            'insert',
            $objectId,
            null,
            '01JD0000000000000000000000',
            '{"fullname":{"new":"John"}}',
            null,
            '1',
            '{"username":"dark.vador"}',
            '2026-01-01 00:00:00.000000',
        ];
    }

    private function createAuditTable(string $table, ?Connection $connection = null): void
    {
        ($connection ?? $this->connection)->executeStatement(\sprintf(
            'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, schema_version INTEGER NOT NULL, type VARCHAR(11) NOT NULL, object_id VARCHAR(255) NOT NULL, discriminator VARCHAR(255) DEFAULT NULL, transaction_id VARCHAR(26) DEFAULT NULL, diffs CLOB DEFAULT NULL, extra_data CLOB DEFAULT NULL, blame_id VARCHAR(255) DEFAULT NULL, blame CLOB DEFAULT NULL, created_at DATETIME NOT NULL)',
            $table,
        ));
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * @return list<string>
     */
    private function objectIds(string $table, ?Connection $connection = null): array
    {
        /** @var list<string> */
        return ($connection ?? $this->connection)->fetchFirstColumn('SELECT object_id FROM '.$table.' ORDER BY id');
    }
}
