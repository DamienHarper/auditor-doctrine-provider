<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Benchmarks;

use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks audit:snapshot (Snapshotter) on BENCH_N posts (default 2,000) written by BENCH_N / 10 authors, created
 * with auditing disabled before each iteration, outside the measured time.
 *
 *   BENCH_N=5000 vendor/bin/phpbench run benchmarks/SnapshotBench.php --report=aggregate
 */
#[Bench\Iterations(5)]
#[Bench\Revs(1)]
#[Bench\BeforeMethods(['setUpSnapshot'])]
#[Bench\AfterMethods(['tearDownSnapshot'])]
class SnapshotBench
{
    use BenchSetupTrait;

    public function setUpSnapshot(): void
    {
        $n = max(10, (int) (getenv('BENCH_N') ?: 2_000));
        $this->buildProvider();
        $this->buildSchema();

        $configuration = $this->provider->getAuditor()->getConfiguration();
        $configuration->disable();

        $authors = [];
        for ($i = 0; $i < intdiv($n, 10); ++$i) {
            $author = new Author();
            $author->setFullname("Author {$i}")->setEmail("author{$i}@bench.test");
            $this->em->persist($author);
            $authors[] = $author;
        }

        for ($i = 0; $i < $n; ++$i) {
            $post = new Post();
            $post->setTitle("Post {$i}")->setBody("Body of post {$i}")->setCreatedAt(new \DateTimeImmutable('2020-01-01'))->setAuthor($authors[$i % \count($authors)]);
            $this->em->persist($post);
        }

        $this->em->flush();
        $this->em->clear();
        $configuration->enable();
    }

    public function tearDownSnapshot(): void
    {
        $this->em->getConnection()->close();
    }

    public function benchSnapshot(): void
    {
        new Snapshotter($this->provider)->snapshot(Post::class);
    }
}
