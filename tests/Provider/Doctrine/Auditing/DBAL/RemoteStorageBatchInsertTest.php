<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Batched audit entries (`batch_size` option) stored on another connection than the audited one are written
 * before the audited transaction is committed, at the latest: they must not outlive an audited transaction that
 * is rolled back, must never be deleted when the audited changes may have been committed, and failing to write
 * them must fail the commit.
 *
 * @internal
 */
#[Small]
final class RemoteStorageBatchInsertTest extends TestCase
{
    use SchemaSetupTrait;

    private const int BATCH_SIZE = 2;

    private CommitFailureMiddleware $commitFailure;

    public function testBufferedAuditEntriesAreWrittenWhenTheCommitSucceeds(): void
    {
        $this->flushAuthors('John Doe');

        $this->assertSame(1, $this->countRows('author'));
        $this->assertSame(1, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testAuditEntriesOfEarlyBatchesAreWrittenWhenTheCommitSucceeds(): void
    {
        $this->flushAuthors('John Doe', 'Jane Doe', 'Luke Skywalker');

        $this->assertSame(3, $this->countRows('author'));
        $this->assertSame(3, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testAuditEntriesAreDeletedWhenTheServerRollsTheCommitBack(): void
    {
        $this->commitFailure->failNextCommit('40001');

        $this->assertFlushFails('John Doe', 'Jane Doe', 'Luke Skywalker');

        $this->assertSame(0, $this->countRows('author'));
        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()), 'The audit entries of a rolled-back transaction must be deleted.');
    }

    public function testAuditEntriesAreKeptWhenTheCommitOutcomeIsUnknown(): void
    {
        // Connection lost during the COMMIT: the server may have committed, the audit entries must not be lost.
        $this->commitFailure->failNextCommit('08006');

        $this->assertFlushFails('John Doe', 'Jane Doe', 'Luke Skywalker');

        $this->assertSame(3, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testFailingToWriteTheAuditEntriesFailsTheCommit(): void
    {
        $this->storageConnection()->executeStatement('DROP TABLE author_audit');

        $this->assertFlushFails('John Doe');

        $this->assertSame(0, $this->countRows('author'), 'Changes whose audit entries cannot be written must not be committed.');
    }

    public function testFailingToWriteAnEarlyBatchFailsTheCommit(): void
    {
        $this->storageConnection()->executeStatement('DROP TABLE author_audit');

        $this->assertFlushFails('John Doe', 'Jane Doe', 'Luke Skywalker');

        $this->assertSame(0, $this->countRows('author'), 'Changes whose audit entries cannot be written must not be committed.');
    }

    public function testNoAuditEntryIsLeftWhenTheAuditFails(): void
    {
        $audited = 0;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$audited): void {
            if (3 === ++$audited) {
                throw new \RuntimeException('audit failed');
            }
        }, 1_000);

        $this->assertFlushFails('John Doe', 'Jane Doe', 'Luke Skywalker');

        $this->assertSame(0, $this->countRows('author'));
        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()), 'The audit entries of the early batch must be deleted.');
    }

    public function testNothingIsWrittenWhenTheAuditFailsBeforeABatchIsWritten(): void
    {
        $audited = 0;
        // the first audit entry is buffered, the batch size is not reached
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$audited): void {
            if (2 === ++$audited) {
                throw new \RuntimeException('audit failed');
            }
        }, 1_000);

        $this->assertFlushFails('John Doe', 'Jane Doe');

        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
        ]);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->commitFailure = new CommitFailureMiddleware();

        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration(['batch_size' => self::BATCH_SIZE]));

        $paths = [
            __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../../Fixtures/Entity/Standard/Blog',
        ];

        // audit entries are stored on their own connection (in memory SQLite database)
        $this->provider->registerStorageService(new StorageService('storage', $this->createEntityManager($paths, 'remote_storage', [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ])));

        // audited connection (another in memory SQLite database) whose COMMIT can be made to fail
        $configuration = new Configuration();
        $configuration->setMiddlewares([$this->commitFailure, new AuditorMiddleware()]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $ormConfiguration = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $ormConfiguration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfiguration->enableNativeLazyObjects(true);
        if (method_exists($ormConfiguration, 'setUseSchemaEditorApi') && method_exists(Schema::class, 'edit')) {
            $ormConfiguration->setUseSchemaEditorApi(true);
        }

        $this->provider->registerAuditingService(new AuditingService('audited', new EntityManager($connection, $ormConfiguration)));

        $auditor->registerProvider($this->provider);
    }

    private function flushAuthors(string ...$fullnames): void
    {
        $entityManager = $this->auditingEntityManager();
        foreach ($fullnames as $fullname) {
            $entityManager->persist(new Author()->setFullname($fullname)->setEmail('author@example.com'));
        }

        $entityManager->flush();
    }

    private function assertFlushFails(string ...$fullnames): void
    {
        try {
            $this->flushAuthors(...$fullnames);
            $this->fail('The flush must fail.');
        } catch (\Throwable $throwable) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $throwable);
        }

        $this->assertFalse($this->auditingEntityManager()->getConnection()->isTransactionActive());
    }

    private function countRows(string $table, ?Connection $connection = null): int
    {
        $connection ??= $this->auditingEntityManager()->getConnection();

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function auditingEntityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
    }

    private function storageConnection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
    }
}
