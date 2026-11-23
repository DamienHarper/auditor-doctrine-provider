<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Retention;

use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\Persistence\Retention\RetentionPruner;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The rows of the audit root index go with the audit entries they point to.
 *
 * @internal
 */
#[Small]
final class RetentionRootIndexTest extends TestCase
{
    use SchemaSetupTrait;

    public function testPrunedEntriesLeaveNoIndexRow(): void
    {
        $connection = $this->connection();
        $connection->executeStatement("UPDATE post_audit SET created_at = '2000-01-01 00:00:00' WHERE type = 'insert'");

        $kept = $connection->fetchOne("SELECT transaction_id FROM post_audit WHERE type = 'update'");
        $this->assertSame(2, $this->indexRows());

        $pruner = new RetentionPruner($this->provider);
        $statements = $pruner->pruneEntity(Post::class, new RetentionPolicy('P1D'), null);

        $this->assertCount(2, $statements, 'The entries, then the index rows.');
        $this->assertSame(1, $this->indexRows());
        $this->assertSame($kept, $connection->fetchOne("SELECT transaction_id FROM audit_root WHERE audit_table = 'post_audit'"));
    }

    public function testADryRunKeepsTheIndexRows(): void
    {
        $this->connection()->executeStatement("UPDATE post_audit SET created_at = '2000-01-01 00:00:00'");

        $statements = new RetentionPruner($this->provider)->pruneEntity(Post::class, new RetentionPolicy('P1D'), null, true);

        $this->assertStringContainsString('audit_root', $statements[1] ?? '');
        $this->assertSame(2, $this->indexRows());
    }

    private function indexRows(): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_root WHERE audit_table = 'post_audit'");
    }

    private function connection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true, 'roots' => ['author']],
        ]);
    }

    private function setupEntities(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();

        $author = new Author()->setFullname('Alice')->setEmail('alice@example.com');
        $post = new Post()->setTitle('Hello')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setAuthor($author);
        $em->persist($author);
        $em->persist($post);
        $em->flush();

        $post->setTitle('Hello world');
        $em->flush();
    }
}
