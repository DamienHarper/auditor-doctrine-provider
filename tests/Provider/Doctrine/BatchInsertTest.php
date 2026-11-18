<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditInsertBuffer;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use DH\Auditor\User\User;
use Doctrine\DBAL\Connection;
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

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(['1'], $this->auditedObjectIds(Author::class));
        $this->assertSame($this->lastAuditId(Author::class), $event->getPayload()['id'] ?? null);
    }

    public function testResetDeactivatesTheBufferWithoutWritingIt(): void
    {
        $buffer = new AuditInsertBuffer();
        $this->provider->useInsertBuffer($buffer);
        $this->provider->persist($this->event('1'));

        $this->provider->reset();
        $this->provider->persist($this->event('2'));

        $this->assertSame(['2'], $this->auditedObjectIds(Author::class), 'Without an active buffer, audit entries are inserted immediately.');
        $this->assertSame(1, $buffer->count(), 'Reset does not write the buffer.');
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
