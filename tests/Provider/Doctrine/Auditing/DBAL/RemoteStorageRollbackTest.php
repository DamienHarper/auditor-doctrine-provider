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
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Audit entries stored on another connection than the audited one are written in their own transaction,
 * before the audited transaction is committed: they must not outlive an audited transaction that is
 * rolled back, and must never be deleted when the audited changes may have been committed.
 *
 * @internal
 */
#[Small]
final class RemoteStorageRollbackTest extends TestCase
{
    use SchemaSetupTrait;

    private CommitFailureMiddleware $commitFailure;

    public function testAuditEntriesAreKeptWhenTheCommitSucceeds(): void
    {
        $this->flushAuthors('John Doe');

        $this->assertSame(1, $this->countRows('author'));
        $this->assertSame(1, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testAuditEntriesAreDeletedWhenTheServerRollsTheCommitBack(): void
    {
        $this->commitFailure->failNextCommit('40001');

        $this->assertFlushFails('John Doe');

        $this->assertSame(0, $this->countRows('author'));
        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()), 'The audit entries of a rolled-back transaction must be deleted.');
    }

    public function testAuditEntriesAreDeletedWhenADeferredConstraintFailsTheCommit(): void
    {
        $this->commitFailure->failNextCommit('23000');

        $this->assertFlushFails('John Doe');

        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testAuditEntriesAreKeptWhenTheCommitOutcomeIsUnknown(): void
    {
        // Connection lost during the COMMIT: the server may have committed, the audit entries must not be lost.
        $this->commitFailure->failNextCommit('08006');

        $this->assertFlushFails('John Doe');

        $this->assertSame(1, $this->countRows('author_audit', $this->storageConnection()));
    }

    public function testAuditEntriesAlreadyWrittenAreDeletedWhenTheAuditFails(): void
    {
        $audited = 0;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$audited): void {
            if (2 === ++$audited) {
                throw new \RuntimeException('audit failed');
            }
        }, 1_000);

        $this->assertFlushFails('John Doe', 'Jane Doe');

        $this->assertSame(0, $this->countRows('author'));
        $this->assertSame(0, $this->countRows('author_audit', $this->storageConnection()), 'The audit entry written before the failure must be deleted.');
    }

    public function testARolledBackCommitOnlyDeletesItsOwnAuditEntries(): void
    {
        $this->flushAuthors('John Doe');

        $this->commitFailure->failNextCommit('40001');
        $this->assertFlushFails('Jane Doe');

        $this->assertSame(1, $this->countRows('author'));
        $this->assertSame(1, $this->countRows('author_audit', $this->storageConnection()), 'The audit entries of a committed transaction must be kept.');
    }

    public function testAFailedDeletionOfAuditEntriesIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, mixed, array<mixed>}> */
            public array $records = [];

            // $message is mixed: psr/log 1.x declares LoggerInterface::log() without parameter types.
            public function log(mixed $level, mixed $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $this->provider->setLogger($logger);

        // the audit table of the storage connection is gone when the audit entries are deleted
        $storageConnection = $this->storageConnection();
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use ($storageConnection): void {
            $storageConnection->executeStatement('ALTER TABLE author_audit RENAME TO author_audit_moved');
        }, -2_000_000); // after the audit entry is written
        $this->commitFailure->failNextCommit('40001');

        try {
            $this->flushAuthors('John Doe');
            $this->fail('The flush must fail.');
        } catch (\Throwable $throwable) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $throwable);
            $sqlStates = [];
            for ($exception = $throwable; $exception instanceof \Throwable; $exception = $exception->getPrevious()) {
                if ($exception instanceof DriverException) {
                    $sqlStates[] = $exception->getSQLState();
                }
            }

            $this->assertContains('40001', $sqlStates, 'The failure of the deletion must not hide the commit failure.');
        } finally {
            $storageConnection->executeStatement('ALTER TABLE author_audit_moved RENAME TO author_audit');
        }

        $this->assertSame(1, $this->countRows('author_audit', $storageConnection), 'The audit entry could not be deleted.');
        $this->assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        $this->assertSame(LogLevel::ERROR, $level);
        $this->assertSame('author_audit', $context['table'] ?? null);
        $this->assertSame($storageConnection->fetchOne('SELECT transaction_id FROM author_audit'), $context['transaction_id'] ?? null);
        $this->assertInstanceOf(\Throwable::class, $context['exception'] ?? null);
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
        $this->provider = new DoctrineProvider($this->createProviderConfiguration());

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
