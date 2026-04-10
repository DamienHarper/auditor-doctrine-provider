<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ConnectionTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class QueryIterateTest extends TestCase
{
    use ConnectionTrait;

    private const string TABLE = 'author_audit';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createConnection();
        $this->connection->executeStatement(
            'CREATE TABLE '.self::TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                schema_version SMALLINT NOT NULL DEFAULT 2,
                type VARCHAR(11) NOT NULL,
                object_id VARCHAR(255) NOT NULL,
                discriminator VARCHAR(255) DEFAULT NULL,
                transaction_id CHAR(26) DEFAULT NULL,
                diffs TEXT DEFAULT NULL,
                extra_data TEXT DEFAULT NULL,
                blame_id VARCHAR(255) DEFAULT NULL,
                blame TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )'
        );

        foreach ([
            ['1', 'insert', '2025-01-01 00:00:00'],
            ['2', 'update', '2025-01-02 00:00:00'],
            ['3', 'remove', '2025-01-03 00:00:00'],
        ] as [$objectId, $type, $createdAt]) {
            $this->connection->insert(self::TABLE, [
                'type' => $type,
                'object_id' => $objectId,
                'created_at' => $createdAt,
                'diffs' => '{}',
            ]);
        }
    }

    public function testIterateReturnsGenerator(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $result = $query->iterate();

        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function testIterateYieldsAllRows(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $rows = iterator_to_array($query->iterate(), false);

        $this->assertCount(3, $rows);
    }

    public function testIterateYieldsAssociativeArrays(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $rows = iterator_to_array($query->iterate(), false);

        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('type', $row);
            $this->assertArrayHasKey('object_id', $row);
            $this->assertArrayHasKey('created_at', $row);
        }
    }

    public function testIterateYieldsRawStringForCreatedAt(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $rows = iterator_to_array($query->iterate(), false);

        $this->assertIsString($rows[0]['created_at'], 'iterate() must yield raw string for created_at, not DateTimeImmutable');
    }

    public function testIterateRespectsFilters(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $query->addFilter(new SimpleFilter(Query::OBJECT_ID, '2'));

        $rows = iterator_to_array($query->iterate(), false);

        $this->assertCount(1, $rows);
        $this->assertSame('2', $rows[0]['object_id']);
    }

    public function testIterateYieldsNothingOnEmptyResult(): void
    {
        $query = new Query(self::TABLE, $this->connection, 'UTC');
        $query->addFilter(new SimpleFilter(Query::OBJECT_ID, 'nonexistent'));

        $rows = iterator_to_array($query->iterate(), false);

        $this->assertCount(0, $rows);
    }
}
