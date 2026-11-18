<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorDriver;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Cat;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Batched audit entries (`batch_size` option) are buffered per audited connection: entity managers sharing a
 * connection share its buffer, written once when the transaction is committed, and each audited connection
 * writes its own buffer when its transaction is committed.
 *
 * @internal
 */
#[Small]
final class MultiConnectionBatchInsertTest extends TestCase
{
    use SchemaSetupTrait;

    public function testEntityManagersSharingAConnectionShareItsBuffer(): void
    {
        $authorEntityManager = $this->entityManager('blog');
        $catEntityManager = $this->entityManager('animals');
        $connection = $authorEntityManager->getConnection();
        $this->assertNotSame($authorEntityManager, $catEntityManager);
        $this->assertSame($connection, $catEntityManager->getConnection());

        $driver = $this->auditorDriver($connection);
        $buffered = [];
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use ($driver, &$buffered): void {
            $buffered[] = $driver->getInsertBuffer()->count();
        }, -2_000_000);

        $connection->beginTransaction();
        $authorEntityManager->persist(new Author()->setFullname('John Doe')->setEmail('john@example.com'));
        $authorEntityManager->flush();
        $catEntityManager->persist(new Cat()->setLabel('Grumpy'));
        $catEntityManager->flush();
        $this->assertCount(2, $driver->getFlusherList(), 'One flusher per entity manager.');
        $connection->commit();

        $this->assertSame([1, 2], $buffered, 'The audit entries of both entity managers are written together.');
        $this->assertTrue($driver->getInsertBuffer()->isEmpty());
        $this->assertSame(1, $this->countAuditEntries(Author::class));
        $this->assertSame(1, $this->countAuditEntries(Cat::class));
    }

    public function testEachAuditedConnectionWritesItsOwnBuffer(): void
    {
        $defaultDriver = $this->auditorDriver($this->entityManager('blog')->getConnection());
        $carEntityManager = $this->entityManager('vehicles');
        $carDriver = $this->auditorDriver($carEntityManager->getConnection());
        $this->assertNotSame($defaultDriver, $carDriver);

        $buffered = [];
        $this->provider->getAuditor()->getEventDispatcher()->addListener(LifecycleEvent::class, static function () use ($defaultDriver, $carDriver, &$buffered): void {
            $buffered[] = [$defaultDriver->getInsertBuffer()->count(), $carDriver->getInsertBuffer()->count()];
        }, -2_000_000);

        $carEntityManager->persist(new Car()->setLabel('Mustang')->setWheels(4));
        $carEntityManager->flush();

        $this->assertSame([[0, 1]], $buffered);
        $this->assertTrue($carDriver->getInsertBuffer()->isEmpty());
        $this->assertSame(1, $this->countAuditEntries(Car::class), 'The audit entries are written on the storage connection.');
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Cat::class => ['enabled' => true],
            Car::class => ['enabled' => true],
        ]);
    }

    /**
     * Audited connection "default" shared by 2 entity managers, audited connection "batch_second" used by a third
     * one, audit entries stored on connection "default".
     */
    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration(['batch_size' => 1_000]));

        $attributes = __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute';
        $fixtures = __DIR__.'/../../Fixtures/Entity';

        $this->provider->registerStorageService(new StorageService('storage', $this->createEntityManager([
            $attributes,
            $fixtures.'/Standard/Blog',
            $fixtures.'/Inheritance',
        ])));
        $this->provider->registerAuditingService(new AuditingService('blog', $this->createEntityManager([$attributes, $fixtures.'/Standard/Blog'])));
        $this->provider->registerAuditingService(new AuditingService('animals', $this->createEntityManager([$attributes, $fixtures.'/Inheritance/Joined'])));
        $this->provider->registerAuditingService(new AuditingService('vehicles', $this->createEntityManager([$attributes, $fixtures.'/Inheritance/SingleTable'], 'batch_second', [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ])));

        $auditor->registerProvider($this->provider);
    }

    private function entityManager(string $auditingService): EntityManagerInterface
    {
        return $this->provider->getAuditingServices()[$auditingService]->getEntityManager();
    }

    private function countAuditEntries(string $entity): int
    {
        $auditTable = $this->provider->getConfiguration()->getEntities()[$entity]['computed_audit_table_name'];
        \assert(\is_string($auditTable));

        return (int) $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$auditTable);
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
