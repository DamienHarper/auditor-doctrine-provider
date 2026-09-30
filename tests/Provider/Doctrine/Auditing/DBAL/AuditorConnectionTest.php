<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorDriver;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
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
