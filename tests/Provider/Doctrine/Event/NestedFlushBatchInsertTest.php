<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Event;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * NestedFlushTest with audit entries written with multi-row INSERT statements (`batch_size` option): batches
 * smaller than the outer transactions, so that some are written before the COMMIT.
 *
 * @see NestedFlushTestsTrait
 *
 * @internal
 */
#[Small]
final class NestedFlushBatchInsertTest extends TestCase
{
    use NestedFlushTestsTrait;
    use SchemaSetupTrait;

    public function testAuditEntriesOfAnOuterTransactionAreWrittenInBatches(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $written = [];
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use ($connection, &$written): void {
            $written[] = (int) $connection->fetchOne('SELECT COUNT(*) FROM author_audit');
        }, -2_000_000);

        $connection->beginTransaction();
        foreach (['John Doe', 'Jane Doe', 'Luke Skywalker'] as $fullname) {
            $em->persist(new Author()->setFullname($fullname)->setEmail('author@example.com'));
            $em->flush();
        }

        $connection->commit();

        $this->assertSame([0, 2, 2], $written, 'The first two audit entries are written early.');
        $this->assertCount(3, new Reader($this->provider)->createQuery(Author::class)->execute());
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['batch_size' => 2]));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
        ]);
    }
}
