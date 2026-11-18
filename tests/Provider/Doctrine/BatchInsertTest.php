<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditInsertBuffer;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\Service\StorageServiceInterface;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use DH\Auditor\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Audit entries written with multi-row INSERT statements when the audited database transaction is committed
 * (`batch_size` option).
 *
 * @internal
 */
#[Small]
final class BatchInsertTest extends TestCase
{
    use SchemaSetupTrait;

    private int $batchSize = 3;

    #[DataProvider('provideBatchedAuditEntriesAreIdenticalToUnbatchedOnesCases')]
    public function testBatchedAuditEntriesAreIdenticalToUnbatchedOnes(int $batchSize): void
    {
        $this->useProviderWithBatchSize(0);
        $this->runScenario();
        $unbatched = $this->snapshot();

        $this->useProviderWithBatchSize($batchSize);
        $this->runScenario();
        $batched = $this->snapshot();

        $this->assertGreaterThan(99, \count($unbatched[Tag::class]), 'The scenario writes more audit entries than a statement holds.');
        $this->assertSame($unbatched, $batched);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideBatchedAuditEntriesAreIdenticalToUnbatchedOnesCases(): iterable
    {
        yield 'batches smaller than a transaction' => [3];

        yield 'batches larger than the per-statement limit' => [1_000];
    }

    public function testBatchedAuditEntriesAreReadBack(): void
    {
        $em = $this->entityManager();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $entries = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(1, $entries);
        $this->assertSame('insert', $entries[0]->type);
        $this->assertSame('John Doe', $entries[0]->getDiffs()['fullname']['new'] ?? null);
        $this->assertSame('dark.vador', $entries[0]->username);
        $this->assertNull($entries[0]->extraData);

        $connection = $this->storageConnection();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->assertSame(
                ['object', 'null', 'object'],
                array_values($connection->fetchNumeric("SELECT jsonb_typeof(diffs), coalesce(jsonb_typeof(extra_data), 'null'), jsonb_typeof(blame) FROM ".$this->auditTable(Author::class)) ?: []),
                'JSON columns hold JSON values, not JSON strings.',
            );
        }
    }

    public function testAuditEntriesAreBufferedWhileTheTransactionIsProcessed(): void
    {
        $ids = [];
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function (LifecycleEvent $event) use (&$ids): void {
            $ids[] = $event->getPayload()['id'] ?? null;
        }, -2_000_000);

        $em = $this->entityManager();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
        $em->flush();

        $this->assertSame([null, null], $ids, 'The id of a buffered audit entry is unknown.');
        $this->assertCount(2, $this->auditedObjectIds(Author::class));

        $event = $this->event('direct');
        $this->provider->persist($event);
        $this->assertArrayHasKey('id', $event->getPayload(), 'The buffer is no longer used once the transaction is committed.');
    }

    public function testTheBufferIsNoLongerUsedAfterAFailure(): void
    {
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function (): never {
            throw new \RuntimeException('audit failed');
        }, 1_000);

        $em = $this->entityManager();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));

        try {
            $em->flush();
            $this->fail('The flush must fail with the audit.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('audit failed', $runtimeException->getMessage());
        }

        $event = $this->event('direct');
        $this->provider->persist($event);
        $this->assertArrayHasKey('id', $event->getPayload());
    }

    public function testAuditEntriesOfARolledBackTransactionAreNotKept(): void
    {
        $em = $this->entityManager();
        $connection = $em->getConnection();

        $connection->beginTransaction();

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $connection->rollBack();

        $this->assertSame([], $this->auditedObjectIds(Author::class));
    }

    /**
     * Audit entries already written by an early batch are rolled back with the audited changes.
     */
    public function testAuditEntriesOfAFailedCommitAreNotKept(): void
    {
        $audited = 0;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$audited): void {
            if (5 === ++$audited) {
                throw new \RuntimeException('audit failed');
            }
        }, 1_000);

        $em = $this->entityManager();
        for ($i = 1; $i <= 5; ++$i) {
            $em->persist(new Author()->setFullname('Author '.$i)->setEmail('author@example.com'));
        }

        try {
            $em->flush();
            $this->fail('The flush must fail with the audit.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('audit failed', $runtimeException->getMessage());
        }

        $this->assertSame(0, (int) $this->storageConnection()->fetchOne('SELECT COUNT(*) FROM author'));
        $this->assertSame([], $this->auditedObjectIds(Author::class), 'The audit entries of the early batch are rolled back.');
    }

    public function testAResetWhileTheTransactionIsProcessedLosesNoAuditEntry(): void
    {
        $audited = 0;
        $provider = $this->provider;
        $provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$audited, $provider): void {
            if (2 === ++$audited) {
                $provider->reset();
            }
        }, -2_000_000);

        $em = $this->entityManager();
        $authors = [];
        for ($i = 1; $i <= 5; ++$i) {
            $em->persist($authors[] = new Author()->setFullname('Author '.$i)->setEmail('author@example.com'));
        }

        $em->flush();

        $expected = array_map(static fn (Author $author): string => (string) $author->getId(), $authors);
        $objectIds = $this->auditedObjectIds(Author::class);
        sort($expected);
        sort($objectIds);
        $this->assertSame($expected, $objectIds);
    }

    /**
     * The auditor ignores the exceptions thrown while an audit entry is persisted: a failure before the entry
     * reaches the buffer must fail the commit, not lose the entry.
     */
    public function testAFailureBeforeTheAuditEntryIsBufferedFailsTheCommit(): void
    {
        $em = $this->entityManager();
        $this->provider->registerStorageService(new StorageService('other', $em));
        $this->provider->setStorageMapper(static function (): never {
            throw new \RuntimeException('no storage');
        });

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));

        $this->assertFlushFailsWith('no storage');

        $this->provider->setStorageMapper(static fn (string $entity, array $storageServices): StorageServiceInterface => $storageServices['default']);
        $this->assertSame(0, (int) $this->storageConnection()->fetchOne('SELECT COUNT(*) FROM author'));
        $this->assertSame([], $this->auditedObjectIds(Author::class));
    }

    /**
     * On PostgreSQL, the transaction is aborted by the failed early INSERT: the next query fails (SQLSTATE 25P02)
     * and must not hide the original failure.
     */
    public function testTheFailureOfAnEarlyBatchIsReportedRatherThanItsConsequences(): void
    {
        $this->useProviderWithBatchSize(2);
        $this->invalidateAuditEntry(2);
        $connection = $this->storageConnection();
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use ($connection): void {
            $connection->fetchOne('SELECT COUNT(*) FROM author');
        }, -2_000_000);

        $em = $this->entityManager();
        foreach (['John Doe', 'Jane Doe', 'Luke Skywalker'] as $fullname) {
            $em->persist(new Author()->setFullname($fullname)->setEmail('author@example.com'));
        }

        $throwable = $this->flushFailure();

        $this->assertCausedBy(NotNullConstraintViolationException::class, $throwable);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $this->assertSame([], $this->auditedObjectIds(Author::class));
    }

    /**
     * Without the buffer, a failing INSERT would be ignored by the auditor: the change would be committed without
     * its audit entry.
     */
    public function testAResetWhileTheTransactionIsProcessedDoesNotHideAFailure(): void
    {
        $provider = $this->provider;
        $reset = false;
        $provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$reset, $provider): void {
            if (!$reset) {
                $reset = true;
                $provider->reset();
            }
        }, -2_000_000);
        $this->invalidateAuditEntry(2);

        $em = $this->entityManager();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));

        $this->assertCausedBy(NotNullConstraintViolationException::class, $this->flushFailure());
        $this->assertSame(0, (int) $this->storageConnection()->fetchOne('SELECT COUNT(*) FROM author'));
        $this->assertSame([], $this->auditedObjectIds(Author::class));
    }

    public function testPersistWithoutAnActiveBufferInsertsImmediately(): void
    {
        $event = $this->event('1');
        $this->provider->persist($event);

        $this->assertSame(['1'], $this->auditedObjectIds(Author::class));
        $this->assertSame($this->lastAuditId(Author::class), $event->getPayload()['id'] ?? null, 'The id of the audit entry is known.');
    }

    public function testPersistWithAnActiveBufferWritesNothingUntilTheBufferIsFlushed(): void
    {
        $buffer = new AuditInsertBuffer();
        $this->provider->useInsertBuffer($buffer);

        $event = $this->event('1');
        $this->provider->persist($event);

        $this->assertSame([], $this->auditedObjectIds(Author::class));
        $this->assertArrayNotHasKey('id', $event->getPayload(), 'The id of a buffered audit entry is unknown.');
        $this->assertSame(1, $buffer->count());

        $buffer->flush();

        $this->assertSame(['1'], $this->auditedObjectIds(Author::class));
    }

    public function testPersistBuffersEveryColumn(): void
    {
        $buffer = new AuditInsertBuffer();
        $this->provider->useInsertBuffer($buffer);

        $event = $this->event('1');
        $this->provider->persist($event);
        $buffer->flush();

        $this->provider->useInsertBuffer(null);
        $reference = $this->event('1');
        $this->provider->persist($reference);

        $rows = $this->storageConnection()->fetchAllAssociative(\sprintf(
            'SELECT %s FROM %s ORDER BY id',
            implode(', ', AuditInsertBuffer::COLUMNS),
            $this->auditTable(Author::class),
        ));
        $this->assertCount(2, $rows);
        $this->assertSame($rows[1], $rows[0], 'A buffered audit entry is written as it would have been immediately.');
    }

    public function testPersistInsertsImmediatelyWhenBatchingIsDisabled(): void
    {
        $provider = new DoctrineProvider($this->createProviderConfiguration(['batch_size' => 0]));
        $provider->registerStorageService(new StorageService('default', $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()));

        $buffer = new AuditInsertBuffer();
        $provider->useInsertBuffer($buffer);

        $event = $this->event('1');
        $provider->persist($event);

        $this->assertCount(0, $buffer);
        $this->assertSame(['1'], $this->auditedObjectIds(Author::class));
        $this->assertSame($this->lastAuditId(Author::class), $event->getPayload()['id'] ?? null);
    }

    public function testResetNeitherWritesNorDeactivatesTheBuffer(): void
    {
        $buffer = new AuditInsertBuffer();
        $this->provider->useInsertBuffer($buffer);
        $this->provider->persist($this->event('1'));

        $this->provider->reset();
        $this->provider->persist($this->event('2'));

        $this->assertSame([], $this->auditedObjectIds(Author::class));
        $this->assertCount(2, $buffer, 'The buffer of the commit in progress is restored once that commit is processed.');
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
        ]);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['batch_size' => $this->batchSize]));

        // a single user: audit entries written by two providers can be compared
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): User => new User('1', 'dark.vador'));
    }

    private function useProviderWithBatchSize(int $batchSize): void
    {
        $this->tearDownEntitySchemas();
        $this->tearDownAuditSchemas();

        $this->batchSize = $batchSize;
        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
    }

    /**
     * Inserts, updates, associations, dissociations and removals, in transactions opened by the flush or explicitly.
     */
    private function runScenario(): void
    {
        $em = $this->entityManager();
        $createdAt = new \DateTimeImmutable('2020-01-17 22:17:34');

        $john = new Author()->setFullname('John')->setEmail('john@example.com');
        $jane = new Author()->setFullname('Jane')->setEmail('jane@example.com');
        $post1 = new Post()->setTitle('First post')->setBody('Body')->setCreatedAt($createdAt);
        $post2 = new Post()->setTitle('Second post')->setBody('Body')->setCreatedAt($createdAt);
        $comment1 = new Comment()->setBody('First comment')->setAuthor('Dark Vador')->setCreatedAt($createdAt);
        $comment2 = new Comment()->setBody('Second comment')->setAuthor('Yoshi')->setCreatedAt($createdAt);
        $tags = [];
        for ($i = 1; $i <= 120; ++$i) {
            $tags[] = new Tag()->setTitle('tag'.$i);
        }

        foreach ([$john, $jane, $post1, $post2, $comment1, $comment2, ...$tags] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $john->setFullname('John Doe');
        $post1->setAuthor($john)->setCoauthor($jane)->addComment($comment1)->addComment($comment2)->addTag($tags[0])->addTag($tags[1]);
        $post2->setAuthor($jane)->addTag($tags[1]);
        $em->flush();

        $post1->removeTag($tags[0])->setCoauthor(null);
        $em->remove($comment2);
        $em->remove($tags[119]);
        $em->flush();

        $connection = $em->getConnection();
        $connection->beginTransaction();

        $john->setEmail('john.doe@example.com');
        $em->flush();
        $em->persist(new Author()->setFullname('Luke')->setEmail('luke@example.com'));
        $em->flush();

        $connection->commit();
    }

    /**
     * Audit entries of every audited entity, without their id and creation date, and with their transaction id
     * replaced by its rank of appearance.
     *
     * @return array<class-string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $connection = $this->storageConnection();
        $transactionIds = [];
        $snapshot = [];
        foreach ([Author::class, Post::class, Comment::class, Tag::class] as $entity) {
            $snapshot[$entity] = [];
            $rows = $connection->fetchAllAssociative(\sprintf(
                'SELECT %s FROM %s ORDER BY id',
                implode(', ', AuditInsertBuffer::COLUMNS),
                $this->auditTable($entity),
            ));
            foreach ($rows as $row) {
                $row['transaction_id'] = $transactionIds[$row['transaction_id']] ??= \count($transactionIds);
                unset($row['created_at']);
                $snapshot[$entity][] = $row;
            }
        }

        return $snapshot;
    }

    /**
     * Makes the $nth audit entry invalid (null object id) before it is persisted.
     */
    private function invalidateAuditEntry(int $nth): void
    {
        $audited = 0;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function (LifecycleEvent $event) use (&$audited, $nth): void {
            if ($nth === ++$audited) {
                $event->setPayload(['object_id' => null] + $event->getPayload());
            }
        });
    }

    private function flushFailure(): \Throwable
    {
        try {
            $this->entityManager()->flush();
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        $this->fail('The flush must fail.');
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertCausedBy(string $class, \Throwable $throwable): void
    {
        $causes = [];
        for ($cause = $throwable; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof $class) {
                $this->addToAssertionCount(1);

                return;
            }

            $causes[] = $cause::class.': '.$cause->getMessage();
        }

        $this->fail(\sprintf("The failure is not caused by %s:\n%s", $class, implode("\n", $causes)));
    }

    /**
     * Flushes the entity manager, expecting a failure caused by an exception with the given message.
     */
    private function assertFlushFailsWith(string $message): void
    {
        try {
            $this->entityManager()->flush();
        } catch (AssertionFailedError $assertionFailedError) {
            throw $assertionFailedError;
        } catch (\Throwable $throwable) {
            $messages = [];
            for ($cause = $throwable; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
                $messages[] = $cause->getMessage();
            }

            $this->assertContains($message, $messages, 'The original failure must be reported.');

            return;
        }

        $this->fail('The flush must fail.');
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
    }

    private function event(string $objectId): LifecycleEvent
    {
        return new LifecycleEvent([
            'entity' => Author::class,
            'table' => $this->auditTable(Author::class),
            'schema_version' => 2,
            'type' => 'insert',
            'object_id' => $objectId,
            'discriminator' => null,
            'transaction_id' => '01JD0000000000000000000000',
            'diffs' => '{"fullname":{"new":"John"}}',
            'extra_data' => '{"reason":"test"}',
            'blame_id' => '1',
            'blame' => '{"username":"dark.vador"}',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function auditTable(string $entity): string
    {
        $auditTable = $this->provider->getConfiguration()->getEntities()[$entity]['computed_audit_table_name'];
        \assert(\is_string($auditTable));

        return $auditTable;
    }

    private function storageConnection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
    }

    /**
     * @return list<string>
     */
    private function auditedObjectIds(string $entity): array
    {
        /** @var list<string> */
        return $this->storageConnection()->fetchFirstColumn('SELECT object_id FROM '.$this->auditTable($entity).' ORDER BY id');
    }

    private function lastAuditId(string $entity): int
    {
        return (int) $this->storageConnection()->fetchOne('SELECT MAX(id) FROM '.$this->auditTable($entity));
    }
}
