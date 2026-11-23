<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\RebuildRootsCommand;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * @internal
 */
#[Small]
final class RebuildRootsCommandTest extends TestCase
{
    use SchemaSetupTrait;

    public function testTheRebuiltIndexIsTheOneWrittenAlongWithTheEntries(): void
    {
        $written = $this->rows();
        $this->assertNotEmpty($written);

        $this->connection()->executeStatement('DELETE FROM audit_root');

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertSame($written, $this->rows());

        $tester->execute([]);
        $this->assertSame($written, $this->rows(), 'A second run writes nothing.');
        $this->assertMatchesRegularExpression('/comment_audit\s+\d+\s+0\s/', $tester->getDisplay(), 'No row written.');
    }

    public function testRowsWrittenByHandAreKept(): void
    {
        $this->connection()->insert('audit_root', [
            'root_class' => Author::class,
            'root_id' => '99',
            'root_path' => 'post.author',
            'audit_table' => 'comment_audit',
            'object_id' => '1',
            'transaction_id' => '01JD0000000000000000000000',
            'created_at' => '2020-01-01 00:00:00',
        ]);
        $before = \count($this->rows());

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount($before, $this->rows(), 'Nothing is missing, nothing is deleted.');
        $this->assertContains([Author::class, '99', 'post.author', 'comment_audit', '1', '01JD0000000000000000000000', '2020-01-01 00:00:00'], $this->rows());
    }

    public function testOneEntity(): void
    {
        $written = $this->rows();
        $this->connection()->executeStatement('DELETE FROM audit_root');

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--entity' => [Comment::class]]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(array_values(array_filter($written, static fn (array $row): bool => 'comment_audit' === $row[3])), $this->rows());
    }

    public function testUnknownEntity(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--entity' => ['App\Unknown']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown audited entities', $tester->getDisplay());
    }

    public function testUnreadableDiffsFail(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('Only a platform without a JSON type stores invalid diffs.');
        }

        $this->connection()->executeStatement("UPDATE comment_audit SET diffs = '{not json' WHERE type = 'insert'");

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('could not be read', $tester->getDisplay());
    }

    public function testUnreadableDiffsOfATableOnThePathFail(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('Only a platform without a JSON type stores invalid diffs.');
        }

        // post.author of a comment goes through the audit table of the post
        $this->connection()->executeStatement("UPDATE post_audit SET diffs = '{not json' WHERE type = 'insert'");

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--entity' => [Comment::class]]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('could not be read', $tester->getDisplay());
    }

    public function testAClassGoneSinceTheEntryKeepsItsName(): void
    {
        $connection = $this->connection();
        $cast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '?::jsonb' : '?';
        foreach ($connection->fetchAllAssociative('SELECT id, diffs FROM post_audit') as $row) {
            $diffs = json_decode((string) $row['diffs'], true, 512, JSON_THROW_ON_ERROR);
            array_walk_recursive($diffs, static function (mixed &$value): void {
                $value = Author::class === $value ? 'App\Removed\Author' : $value;
            });
            $connection->executeStatement(\sprintf('UPDATE post_audit SET diffs = %s WHERE id = ?', $cast), [json_encode($diffs, JSON_THROW_ON_ERROR), $row['id']]);
        }

        $connection->executeStatement('DELETE FROM audit_root');

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();

        $classes = array_unique(array_column($this->rows(), 0));
        $this->assertContains('App\Removed\Author', $classes);
        $this->assertContains(Post::class, $classes, 'The other roots are still written.');
    }

    public function testAnotherRunIsRefused(): void
    {
        // the store LockableTrait picks
        $lock = new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore())->createLock('audit:roots:rebuild');
        $this->assertTrue($lock->acquire());

        try {
            $tester = new CommandTester($this->createCommand());
            $tester->execute([]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString('already running', $tester->getDisplay());
        } finally {
            $lock->release();
        }
    }

    public function testEntriesWithoutTransactionIdAreSkipped(): void
    {
        $this->connection()->executeStatement('DELETE FROM audit_root');
        $this->connection()->executeStatement("UPDATE comment_audit SET transaction_id = NULL WHERE type = 'insert'");

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertMatchesRegularExpression('/comment_audit\s+\d+\s+\d+\s+1\s/', $tester->getDisplay(), 'The insert of the comment is counted as skipped.');
        $this->assertStringContainsString('1 entries were skipped', $tester->getDisplay(true), 'The skipped entries are reported.');
        $this->assertNotContains('', array_column($this->rows(), 5));
    }

    public function testDryRun(): void
    {
        $this->connection()->executeStatement('DELETE FROM audit_root');

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
        $this->assertSame([], $this->rows());
    }

    /**
     * @return list<list<string>>
     */
    private function rows(): array
    {
        $rows = $this->connection()->fetchAllNumeric('SELECT root_class, root_id, root_path, audit_table, object_id, transaction_id, created_at FROM audit_root');
        $rows = array_map(static fn (array $row): array => array_map(strval(...), $row), $rows);
        sort($rows);

        return $rows;
    }

    private function connection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
    }

    private function createCommand(): RebuildRootsCommand
    {
        return new RebuildRootsCommand()->setAuditor($this->provider->getAuditor());
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true, 'roots' => ['author', 'coauthor']],
            Comment::class => ['enabled' => true, 'roots' => ['post', 'post.author']],
            Tag::class => ['enabled' => true],
        ]);
    }

    private function setupEntities(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();

        $alice = new Author()->setFullname('Alice')->setEmail('alice@example.com');
        $bob = new Author()->setFullname('Bob')->setEmail('bob@example.com');
        $post = new Post()->setTitle('Hello')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setAuthor($alice);
        $comment = new Comment()->setBody('First')->setAuthor('Carol')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setPost($post);
        foreach ([$alice, $bob, $post, $comment] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $post->setTitle('Hello world')->setCoauthor($bob);
        $em->flush();

        $comment->setBody('First!');
        $em->flush();

        $post->setAuthor($bob)->setCoauthor(null);
        $em->flush();

        $comment->setBody('First!!');
        $em->flush();

        $em->remove($comment);
        $em->flush();
    }
}
