<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\TransactionSummary;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class QueryTransactionsTest extends TestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private const array ENTITIES = [Author::class, Post::class, Comment::class, Tag::class];

    public function testTransactionsOfATable(): void
    {
        $query = $this->query(Post::class);
        $expected = $this->expected([$query]);

        $this->assertGreaterThan(1, \count($expected), 'The blog fixtures must have produced several transactions on posts.');
        $this->assertTrue(array_any($expected, static fn (array $summary): bool => $summary[2] > 1), 'One transaction of the fixtures must have several post entries.');
        $this->assertSame($expected, $this->describe($query->transactions(100)));
        $this->assertSame(\count($expected), $query->countTransactions());
    }

    public function testPagesOfTransactions(): void
    {
        $query = $this->query(Post::class);
        $expected = $this->expected([$query]);

        $this->assertSame(\array_slice($expected, 0, 2), $this->describe($query->transactions(2)));
        $this->assertSame(\array_slice($expected, 1, 2), $this->describe($query->transactions(2, 1)));
        $this->assertSame([], $query->transactions(2, \count($expected)));
    }

    public function testFiltersApply(): void
    {
        $query = $this->query(Post::class)->addFilter(new SimpleFilter(Query::TYPE, 'update'));
        $expected = $this->expected([$query]);

        $this->assertNotSame($this->expected([$this->query(Post::class)]), $expected);
        $this->assertSame($expected, $this->describe($query->transactions(100)));
        $this->assertSame(\count($expected), $query->countTransactions());
    }

    public function testNothingToRead(): void
    {
        $query = $this->query(Post::class)->addFilter(new SimpleFilter(Query::OBJECT_ID, 'none'));

        $this->assertSame([], $query->transactions(10));
        $this->assertSame(0, $query->countTransactions());
        $this->assertSame(0, Query::countTransactionsOf([]));
        $this->assertSame([], Query::transactionsOf([], 10));
    }

    public function testTransactionsAcrossTables(): void
    {
        $queries = array_map($this->query(...), self::ENTITIES);
        $expected = $this->expected($queries);

        $perTable = array_sum(array_map(static fn (Query $query): int => $query->countTransactions(), $queries));
        $this->assertGreaterThan(\count($expected), $perTable, 'A transaction of the fixtures must touch several tables.');
        $this->assertSame($expected, $this->describe(Query::transactionsOf($queries, 100)));
        $this->assertSame(\array_slice($expected, 1, 2), $this->describe(Query::transactionsOf($queries, 2, 1)));
        $this->assertSame(\count($expected), Query::countTransactionsOf($queries));
    }

    public function testEachTableKeepsItsOwnFilterValues(): void
    {
        $posts = $this->query(Post::class)->addFilter(new SimpleFilter(Query::TYPE, 'insert'));
        $comments = $this->query(Comment::class)->addFilter(new SimpleFilter(Query::TYPE, 'update'));

        $this->assertSame($this->expected([$posts, $comments]), $this->describe(Query::transactionsOf([$posts, $comments], 100)));
        $this->assertSame(\count($this->expected([$posts, $comments])), Query::countTransactionsOf([$posts, $comments]));
    }

    public function testTransactionsAcrossConnections(): void
    {
        $posts = $this->query(Post::class);
        $comments = $this->query(Comment::class);
        $expected = $this->expected([$posts, $comments]);

        // the columns of the post audit table that tell transactions, copied to a connection of its own
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $other->executeStatement(\sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY, transaction_id VARCHAR(255) NULL, created_at VARCHAR(32) NOT NULL)', $posts->getTable()));
        foreach ($posts->getConnection()->fetchAllAssociative(\sprintf('SELECT id, transaction_id, created_at FROM %s', $posts->getTable())) as $row) {
            $other->insert($posts->getTable(), $row);
        }

        $copy = new Query($posts->getTable(), $other, $posts->getTimezone()->getName());

        $this->assertSame($expected, $this->describe(Query::transactionsOf([$copy, $comments], 100)));
        $this->assertSame(\array_slice($expected, 2, 3), $this->describe(Query::transactionsOf([$copy, $comments], 3, 2)));
        $this->assertSame($posts->countTransactions() + $comments->countTransactions(), Query::countTransactionsOf([$copy, $comments]), 'A transaction is counted once per connection.');
    }

    public function testAnEntryWithoutTransactionIsATransactionOfItsOwn(): void
    {
        $query = $this->query(Post::class);
        $connection = $query->getConnection();
        $ids = array_map(static fn (Entry $entry): int => (int) $entry->id, $query->execute());
        \assert(\count($ids) >= 2);
        $connection->executeStatement(\sprintf('UPDATE %s SET transaction_id = NULL WHERE id IN (%d, %d)', $query->getTable(), $ids[0], $ids[1]));

        $expected = $this->expected([$query]);
        $alone = array_values(array_filter($this->describe($query->transactions(100)), static fn (array $summary): bool => str_starts_with($summary[0], $query->getTable().'#')));

        $this->assertSame($expected, $this->describe($query->transactions(100)));
        $this->assertCount(2, $alone);
        $this->assertSame(\count($expected), $query->countTransactions());
        $this->assertSame(\count($this->expected([$query, $this->query(Comment::class)])), Query::countTransactionsOf([$query, $this->query(Comment::class)]));
    }

    public function testATableNotMigratedToSchemaV2(): void
    {
        $query = $this->query(Post::class);
        $expected = $this->expected([$query]);
        $this->assertSame(Query::TRANSACTION_ID, $query->getTransactionColumn());
        $query->getConnection()->executeStatement(\sprintf('ALTER TABLE %s RENAME COLUMN transaction_id TO transaction_hash', $query->getTable()));
        $query = $this->query(Post::class);

        $this->assertSame(Query::LEGACY_TRANSACTION_HASH, $query->getTransactionColumn());
        $this->assertSame($expected, $this->describe($query->transactions(100)));
        $this->assertSame(\count($expected), $query->countTransactions());
    }

    public function testInvalidPage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query(Post::class)->transactions(0);
    }

    private function query(string $entity): Query
    {
        return $this->createReader()->createQuery($entity, ['page_size' => null]);
    }

    /**
     * Transactions computed from the entries the queries read: key, date of the most recent entry, number of entries.
     *
     * @param list<Query> $queries
     *
     * @return list<array{string, string, int}>
     */
    private function expected(array $queries): array
    {
        $transactions = [];
        $order = [];
        foreach ($queries as $query) {
            foreach ((clone $query)->resetQueryPart('limit')->execute() as $entry) {
                $key = $entry->transactionId ?? $query->getTable().'#'.$entry->id;
                $date = max($entry->createdAt->format('Y-m-d H:i:s.u'), $transactions[$key][1] ?? '');
                $transactions[$key] = [$key, $date, ($transactions[$key][2] ?? 0) + 1];
                // most recent first, then by transaction id; entries without transaction id last, by table and id
                $order[$key] = [$date, $entry->transactionId ?? '', null === $entry->transactionId ? $query->getTable() : '', null === $entry->transactionId ? (int) $entry->id : 0];
            }
        }

        uksort($transactions, static fn (string $a, string $b): int => $order[$b] <=> $order[$a]);

        return array_values($transactions);
    }

    /**
     * @param list<TransactionSummary> $summaries
     *
     * @return list<array{string, string, int}>
     */
    private function describe(array $summaries): array
    {
        return array_map(static fn (TransactionSummary $summary): array => [$summary->key(), $summary->createdAt->format('Y-m-d H:i:s.u'), $summary->entries], $summaries);
    }
}
