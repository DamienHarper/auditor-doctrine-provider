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
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

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
        $this->assertSame($written, $this->rows(), 'A second run replaces the rows.');
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
        $rows = array_values(array_unique(array_map(serialize(...), $rows)));
        sort($rows);

        return array_map(static fn (string $row): array => unserialize($row), $rows);
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
