<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorDriver;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1060Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * A flusher that throws in AuditorConnection::commit().
 *
 * DBAL counts the transaction as finished even though commit() threw, so nobody calls rollBack()
 * afterwards. The flusher used to stay in the driver's list, which outlives the entity manager
 * (e.g. in a FrankenPHP or RoadRunner worker), and failed every later commit on the connection,
 * while the transaction on the server stayed open.
 *
 * The audit entries held by a flusher that never ran (failed commit or rollback) must not be
 * written with the next commit either.
 *
 * @internal
 */
#[Small]
final class AuditorConnectionTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testAFailingFlusherIsDroppedAndTheTransactionRolledBack(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $driver = $this->auditorDriver($connection);
        $driver->addFlusher(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));

        try {
            $em->flush();
            $this->fail('The flush must fail with the flusher.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('audit failed', $runtimeException->getMessage());
        }

        $this->assertSame([], $driver->getFlusherList(), 'A failed flusher must not run again with the next commit.');
        $this->assertFalse($connection->isTransactionActive());
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'), 'The failed flush must be rolled back.');
    }

    public function testAFailingRollbackDoesNotHideTheFlusherFailure(): void
    {
        $wrappedConnection = $this->createStub(DriverConnection::class);
        $wrappedConnection->method('rollBack')->willThrowException(new \LogicException('connection lost'));
        $wrappedDriver = $this->createStub(Driver::class);
        $wrappedDriver->method('connect')->willReturn($wrappedConnection);

        $driver = new AuditorDriver($wrappedDriver);
        $driver->addFlusher(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        $this->expectExceptionObject(new \RuntimeException('audit failed'));

        try {
            $driver->connect([])->commit();
        } finally {
            $this->assertSame([], $driver->getFlusherList());
        }
    }

    /**
     * The audit fails when committing an explicit transaction: the entity manager stays open and is reused.
     */
    public function testAFailedAuditIsNotReplayedOnTheNextFlush(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();
        $fail = true;
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use (&$fail): void {
            if ($fail) {
                $fail = false;

                throw new \RuntimeException('audit failed');
            }
        }, 1_000);

        $connection->beginTransaction();
        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        try {
            $connection->commit();
            $this->fail('The commit must fail with the audit.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('audit failed', $runtimeException->getMessage());
        }

        $this->assertFalse($connection->isTransactionActive());
        $this->assertSame([], $this->auditorDriver($connection)->getFlusherList());
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM author_audit'));

        $em->clear();
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
        $em->flush();

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(1, $audits, 'The audit entries of the rolled-back flush must not be replayed.');
    }

    /**
     * An explicit rollback after a flush drops the flusher, the audit entries it was holding must go with it.
     */
    public function testARolledBackFlushIsNotAuditedOnTheNextFlush(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();

        $connection->beginTransaction();

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();

        $connection->rollBack();

        $em->clear();
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
        $em->flush();

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM author'));
        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(1, $audits, 'The audit entries of the rolled-back flush must not be replayed.');
    }

    public function testFlushesOfTheSameTransactionAreAuditedOnceWithTheSameTransactionId(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $connection = $em->getConnection();

        $connection->beginTransaction();

        $em->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $em->flush();
        $em->persist(new Author()->setFullname('Jane Doe')->setEmail('jane@example.com'));
        $em->flush();
        $this->assertCount(1, $this->auditorDriver($connection)->getFlusherList(), 'One flusher per database transaction.');
        $connection->commit();

        $audits = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(2, $audits);
        $this->assertSame($audits[0]->transactionId, $audits[1]->transactionId);
    }

    /**
     * DBAL creates, releases and rolls back savepoints through Driver\Connection::exec(), with the syntax of the
     * platform: the driver tracks them to discard the audit entries of a rolled-back nested transaction.
     */
    #[DataProvider('provideTheSavepointsAreTrackedWithTheSyntaxOfEachPlatformCases')]
    public function testTheSavepointsAreTrackedWithTheSyntaxOfEachPlatform(AbstractPlatform $platform): void
    {
        $driver = $this->stubbedAuditorDriver();
        $connection = $driver->connect([]);

        $connection->exec($platform->createSavePoint('DOCTRINE_2'));

        $outer = $driver->getCurrentSavepoint();
        $this->assertSame('DOCTRINE_2', $outer?->name);

        $connection->exec($platform->createSavePoint('DOCTRINE_3'));
        $inner = $driver->getCurrentSavepoint();
        $this->assertSame('DOCTRINE_3', $inner?->name);
        $this->assertSame($outer, $inner->parent);

        $connection->exec($platform->rollbackSavePoint('DOCTRINE_2'));
        $this->assertTrue($outer->isRolledBack());
        $this->assertTrue($inner->isRolledBack(), 'The savepoints created after the one rolled back to are rolled back too.');

        // the savepoint rolled back to is still established: the changes made after the rollback are not rolled back
        $current = $driver->getCurrentSavepoint();
        $this->assertSame('DOCTRINE_2', $current?->name);
        $this->assertFalse($current->isRolledBack());
        $this->assertNull($current->parent);

        $connection->commit();
        $this->assertNull($driver->getCurrentSavepoint(), 'The savepoints are over with the transaction.');
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function provideTheSavepointsAreTrackedWithTheSyntaxOfEachPlatformCases(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform()];

        yield 'MySQL' => [new MySQL80Platform()];

        yield 'MariaDB' => [new MariaDB1060Platform()];

        yield 'PostgreSQL' => [new PostgreSQLPlatform()];

        yield 'SQL Server' => [new SQLServerPlatform()];

        yield 'Oracle' => [new OraclePlatform()];
    }

    public function testAReleasedSavepointHandsItsChangesToItsParent(): void
    {
        $driver = $this->stubbedAuditorDriver();
        $connection = $driver->connect([]);

        $connection->exec('SAVEPOINT DOCTRINE_2');

        $outer = $driver->getCurrentSavepoint();
        $connection->exec('SAVEPOINT DOCTRINE_3');
        $inner = $driver->getCurrentSavepoint();
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_3');

        $this->assertSame($outer, $driver->getCurrentSavepoint());
        $this->assertFalse($inner?->isRolledBack());

        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        $this->assertTrue($inner?->isRolledBack(), 'The changes of a released savepoint belong to its parent.');
    }

    public function testOtherStatementsAndUnknownSavepointsAreIgnored(): void
    {
        $driver = $this->stubbedAuditorDriver();
        $connection = $driver->connect([]);

        $connection->exec('CREATE TABLE savepoint (id INT)');
        $connection->exec('RELEASE SAVEPOINT UNKNOWN');
        $connection->exec('ROLLBACK TO SAVEPOINT UNKNOWN');
        $this->assertNull($driver->getCurrentSavepoint());

        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->rollBack();
        $this->assertNull($driver->getCurrentSavepoint(), 'The savepoints are over with the transaction.');
    }

    private function stubbedAuditorDriver(): AuditorDriver
    {
        $wrappedConnection = $this->createStub(DriverConnection::class);
        $wrappedConnection->method('exec')->willReturn(0);
        $wrappedDriver = $this->createStub(Driver::class);
        $wrappedDriver->method('connect')->willReturn($wrappedConnection);

        return new AuditorDriver($wrappedDriver);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
        ]);
    }

    private function auditorDriver(Connection $connection): AuditorDriver
    {
        $driver = $connection->getDriver();
        while (!$driver instanceof AuditorDriver) {
            $this->assertInstanceOf(AbstractDriverMiddleware::class, $driver, 'The auditor driver middleware is not registered.');
            $driver = \Closure::bind(fn (): mixed => $this->wrappedDriver, $driver, AbstractDriverMiddleware::class)();
        }

        return $driver;
    }
}
