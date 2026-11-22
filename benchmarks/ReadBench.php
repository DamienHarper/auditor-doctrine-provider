<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Benchmarks;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks the read model (Reader / Query) on a seeded audit table.
 *
 * BENCH_READ_ROWS audit rows of Post (default 50,000) and BENCH_EXTRA_TABLES unrelated tables (default 200, as in
 * an application database) are created before each iteration, outside the measured time.
 *
 *   BENCH_READ_ROWS=100000 vendor/bin/phpbench run benchmarks/ReadBench.php --report=aggregate
 */
#[Bench\Iterations(5)]
#[Bench\Revs(5)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods(['setUpRead'])]
#[Bench\AfterMethods(['tearDownRead'])]
class ReadBench
{
    use BenchSetupTrait;

    private int $rows;

    private Reader $reader;

    public function setUpRead(): void
    {
        $this->rows = max(100, (int) (getenv('BENCH_READ_ROWS') ?: 50_000));
        $this->buildProvider();
        $this->buildSchema();

        $connection = $this->em->getConnection();
        $extraTables = max(0, (int) (getenv('BENCH_EXTRA_TABLES') ?: 200));
        for ($i = 0; $i < $extraTables; ++$i) {
            $connection->executeStatement(\sprintf('CREATE TABLE bench_extra_%d (id INT NOT NULL PRIMARY KEY, label VARCHAR(50) NOT NULL, amount INT DEFAULT NULL)', $i));
            $connection->executeStatement(\sprintf('CREATE INDEX bench_extra_%1$d_label_idx ON bench_extra_%1$d (label)', $i));
        }

        $this->seedAuditRows(Post::class, $this->rows);
        $this->seedAuditRows(Author::class, 1_000);
        $this->reader = new Reader($this->provider);
    }

    public function tearDownRead(): void
    {
        $this->em->getConnection()->close();
    }

    /**
     * History of one object, first page.
     */
    public function benchHistory(): void
    {
        $this->reader->createQuery(Post::class, ['object_id' => '1234', 'page' => 1, 'page_size' => 50])->execute();
    }

    /**
     * Every entry of the entity, first page.
     */
    public function benchListFirstPage(): void
    {
        $this->reader->createQuery(Post::class, ['page' => 1, 'page_size' => 50])->execute();
    }

    /**
     * Every entry of the entity, a page in the middle of the table.
     */
    public function benchListDeepPage(): void
    {
        $this->reader->createQuery(Post::class, ['page' => intdiv($this->rows, 100), 'page_size' => 50])->execute();
    }

    /**
     * Every entry of the entity, number of entries (pagination).
     */
    public function benchCount(): void
    {
        $this->reader->createQuery(Post::class)->count();
    }

    /**
     * Entries of one transaction across every audited entity (viewer transaction page).
     */
    public function benchTransaction(): void
    {
        $this->reader->getAuditsByTransactionId(\sprintf('%026d', 777));
    }
}
