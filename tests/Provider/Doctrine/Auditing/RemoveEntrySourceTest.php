<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue112\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Owner;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The source of a remove entry for an entity whose primary key is not named "id", with and without
 * full_snapshot_on_remove.
 *
 * @internal
 */
#[Small]
final class RemoveEntrySourceTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    private bool $fullSnapshotOnRemove = false;

    public function testTheSourceOfARemovedEntityIsKeyedByItsPrimaryKeyName(): void
    {
        $id = $this->removeDummyEntity();

        $this->assertSame([
            'class' => DummyEntity::class,
            'label' => DummyEntity::class.'#'.$id,
            'pkName' => 'primaryKey',
            'primaryKey' => $id,
            'table' => 'author',
        ], $this->lastEntry(DummyEntity::class)->getDiffSource(), 'The identifier is written once, under the primary key name, as in the other entries.');
    }

    public function testTheSnapshotOfARemovedEntityHoldsItsGeneratedIdentifierInTheSourceOnly(): void
    {
        $this->enableFullSnapshotOnRemove();
        $id = $this->removeDummyEntity();

        $entry = $this->lastEntry(DummyEntity::class);
        $this->assertSame([
            'class' => DummyEntity::class,
            'label' => DummyEntity::class.'#'.$id,
            'pkName' => 'primaryKey',
            'primaryKey' => $id,
            'table' => 'author',
        ], $entry->getDiffSource());
        $this->assertArrayNotHasKey('primaryKey', $entry->getDiffs(), 'Doctrine has reset the generated identifier: it is not a change.');
    }

    public function testTheSnapshotOfARemovedEntityKeepsItsNaturalIdentifier(): void
    {
        $this->enableFullSnapshotOnRemove();
        $entityManager = $this->entityManager();
        $owner = new Owner('ACME', 'Acme');
        $entityManager->persist($owner);
        $entityManager->flush();

        $entityManager->remove($owner);
        $entityManager->flush();

        $entry = $this->lastEntry(Owner::class);
        $this->assertSame('remove', $entry->type);
        $this->assertSame('ACME', $entry->getDiffSource()['code'] ?? null);
        $this->assertSame(['new' => null, 'old' => 'ACME'], $entry->getDiffs()['code'], 'Doctrine leaves a natural identifier on the removed entity.');
        $this->assertSame(['new' => null, 'old' => 'Acme'], $entry->getDiffs()['name']);
    }

    private function removeDummyEntity(): int
    {
        $entityManager = $this->entityManager();
        $entity = new DummyEntity();
        $entityManager->persist($entity);
        $entityManager->flush();

        $id = $entity->getPrimaryKey();

        $entityManager->remove($entity);
        $entityManager->flush();

        return $id;
    }

    private function lastEntry(string $entity): Entry
    {
        $audits = $this->createReader()->createQuery($entity)->execute();
        $this->assertSame('remove', $audits[0]->type);

        return $audits[0];
    }

    private function enableFullSnapshotOnRemove(): void
    {
        // the option is read-only: the provider is rebuilt on the same schema
        $this->fullSnapshotOnRemove = true;
        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(DummyEntity::class)->getEntityManager();
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration(['full_snapshot_on_remove' => $this->fullSnapshotOnRemove]));

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/Issue112',
            __DIR__.'/../Fixtures/Root',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            DummyEntity::class => ['enabled' => true],
            Owner::class => ['enabled' => true],
        ]);
    }
}
