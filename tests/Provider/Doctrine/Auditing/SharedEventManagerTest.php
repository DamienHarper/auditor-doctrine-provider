<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Auditing entity managers sharing an event manager, as the entity managers of one DoctrineBundle connection do.
 *
 * @internal
 */
#[Small]
final class SharedEventManagerTest extends TestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    public function testFlushIsAuditedOnce(): void
    {
        $storageServices = [Tag::class => $this->provider->getStorageServiceForEntity(Tag::class)];
        $entityManager = $storageServices[Tag::class]->getEntityManager();
        $this->assertInstanceOf(EntityManager::class, $entityManager);
        $this->provider->registerAuditingService(new AuditingService('second', new EntityManager($entityManager->getConnection(), $entityManager->getConfiguration(), $entityManager->getEventManager())));

        $reader = $this->createReader();
        $before = \count($reader->createQuery(Tag::class)->execute());

        $entityManager->persist(new Tag()->setTitle('shared'));
        $this->flushAll($storageServices);

        $this->assertCount($before + 1, $reader->createQuery(Tag::class)->execute(), 'The flush is audited once.');
    }
}
