<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Event;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Flushes run inside an outer database transaction: a failed flush only rolls back to its savepoint, so its
 * audit entries must not be written when the outer transaction is committed. The entries of the successful
 * flushes must all be written.
 *
 * @internal
 */
#[Small]
final class NestedFlushTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testAFlushRolledBackToItsSavepointIsNotAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $em->getEventManager()->addEventListener(Events::postPersist, new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                $object = $args->getObject();
                if ($object instanceof Author && 'Broken' === $object->getFullname()) {
                    throw new \RuntimeException('flush failed');
                }
            }
        });

        $connection->beginTransaction();

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $em->persist(new Author()->setFullname('Broken')->setEmail('broken@example.com'));

        try {
            $em->flush();
            $this->fail('The flush must fail.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('flush failed', $runtimeException->getMessage());
        }

        $this->assertTrue($connection->isTransactionActive(), 'Only the savepoint of the failed flush is rolled back.');
        $connection->commit();

        $this->assertSame(['John Doe'], $connection->fetchFirstColumn('SELECT fullname FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(1, $audits, 'The audit entries of the flush rolled back to its savepoint must not be written.');
    }

    public function testFlushesOfAnOuterTransactionAreAllAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();

        $connection->beginTransaction();

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
        $em->flush();

        $connection->commit();

        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(2, $audits);
        $this->assertSame($audits[0]->transactionId, $audits[1]->transactionId);
    }

    public function testEveryKindOfChangeOfAnOuterTransactionIsAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();

        $john = new Author()->setFullname('John Doe')->setEmail('john@example.com');
        $jane = new Author()->setFullname('Jane Doe')->setEmail('jane@example.com');
        $tag1 = new Tag()->setTitle('tag1');
        $tag2 = new Tag()->setTitle('tag2');
        $post = new Post()->setTitle('First post')->setBody('Body')->setCreatedAt(new \DateTimeImmutable())->addTag($tag1);
        foreach ([$john, $jane, $tag1, $tag2, $post] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $connection->beginTransaction();
        $john->setFullname('John Changed');
        $em->remove($jane);
        $post->addTag($tag2)->removeTag($tag1);
        $em->flush();
        // soft delete (Gedmo): postSoftDelete is dispatched before the auditor's onFlush
        $em->remove($post);
        $em->flush();

        $connection->commit();

        $reader = new Reader($this->provider);
        $authorUpdate = $reader->createQuery(Author::class, ['type' => 'update'])->execute();
        $this->assertCount(1, $authorUpdate);
        $transactionId = $authorUpdate[0]->transactionId;

        $types = [];
        foreach ([Author::class, Post::class] as $entity) {
            foreach ($reader->createQuery($entity)->execute() as $entry) {
                if ($entry->transactionId === $transactionId) {
                    $types[] = $entity.':'.$entry->type;
                }
            }
        }

        sort($types);
        $this->assertSame([
            Author::class.':remove',
            Author::class.':update',
            Post::class.':associate',
            Post::class.':dissociate',
            Post::class.':remove',
        ], $types);
    }

    /**
     * A listener flushing again from postFlush, before the auditor's postFlush listener has run, must not
     * make the auditor lose the entries of the outer flush.
     */
    public function testAFlushFromAnotherPostFlushListenerDoesNotLoseAuditEntries(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $eventManager = $em->getEventManager();
        $subscriber = array_values(array_filter(
            $eventManager->getListeners(Events::postFlush),
            static fn (object $listener): bool => $listener instanceof DoctrineSubscriber,
        ))[0];
        $eventManager->removeEventListener([Events::postFlush], $subscriber);
        $eventManager->addEventListener(Events::postFlush, new class {
            private bool $flushed = false;

            public function postFlush(PostFlushEventArgs $args): void
            {
                if (!$this->flushed) {
                    $this->flushed = true;
                    $args->getObjectManager()->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
                    $args->getObjectManager()->flush();
                }
            }
        });
        $eventManager->addEventListener(Events::postFlush, $subscriber);

        $connection->beginTransaction();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $connection->commit();

        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(2, $audits, 'The audit entries of committed changes must not be lost.');
    }

    /**
     * A listener of the audit events flushing the same entity manager runs while the audit entries are written:
     * the entries of that flush must be written too, with the same transaction id.
     */
    public function testAFlushFromAnAuditEventListenerIsAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $existing = new Author()->setFullname('Existing')->setEmail('existing@example.com');
        $em->persist($existing);
        $em->flush();

        $flushed = false;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$flushed, $em, $existing): void {
            if (!$flushed) {
                $flushed = true;
                $existing->setFullname('Existing changed');
                $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
                $em->flush();
            }
        }, 10);

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $this->assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class, ['type' => ['insert', 'update']])->execute();
        $transactionIds = [];
        $entries = [];
        foreach ($audits as $entry) {
            if ('1' === $entry->objectId && 'insert' === $entry->type) {
                continue; // the entry of the initial flush
            }

            $entries[] = $entry->type.':'.$entry->objectId;
            $transactionIds[] = $entry->transactionId;
        }

        sort($entries);
        $this->assertSame(['insert:2', 'insert:3', 'update:1'], $entries, 'The audit entries of the flush run from a listener must not be lost.');
        $this->assertCount(1, array_unique($transactionIds), 'Both flushes are written in the same database transaction.');
    }

    /**
     * A listener of the audit events flushing another entity manager sharing the connection registers a flusher
     * while the flushers run: it must run before the commit.
     */
    public function testAFlushOfAnotherEntityManagerFromAnAuditEventListenerIsAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $otherEm = new EntityManager($connection, $em->getConfiguration(), $em->getEventManager());

        $flushed = false;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$flushed, $otherEm): void {
            if (!$flushed) {
                $flushed = true;
                $otherEm->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
                $otherEm->flush();
            }
        }, 10);

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(2, $audits, 'The audit entries of the other entity manager must not be lost.');

        // the other entity manager's next flush is audited on its own
        $otherEm->persist(new Author()->setFullname('Jim Doe')->setEmail('jim@example.com'));
        $otherEm->flush();

        $this->assertCount(3, new Reader($this->provider)->createQuery(Author::class)->execute());
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
