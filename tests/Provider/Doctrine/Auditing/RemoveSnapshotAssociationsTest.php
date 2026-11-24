<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\Bundle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Owner;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * With full_snapshot_on_remove, the remove entry records the owning-side to-one associations of the removed
 * entity, as an insert does.
 *
 * @internal
 */
#[Small]
final class RemoveSnapshotAssociationsTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testTheSnapshotRecordsTheTargetOfAToOneAssociation(): void
    {
        $entityManager = $this->entityManager();
        $car = new Car('car', new Owner('ACME', 'Acme'));
        $entityManager->persist($car->owner);
        $entityManager->persist($car);
        $entityManager->flush();

        $entityManager->remove($car);
        $entityManager->flush();

        $diffs = $this->lastEntry(Car::class)->getDiffs();
        $this->assertSame(['new' => null, 'old' => 'car'], $diffs['label']);
        $this->assertSame([
            'new' => null,
            'old' => ['class' => Owner::class, 'code' => 'ACME', 'label' => 'Acme', 'pkName' => 'code', 'table' => 'root_owner'],
        ], $diffs['owner']);
        $this->assertArrayNotHasKey('zone', $diffs, 'A null association is left out, as in an insert.');
    }

    public function testTheSnapshotLabelsTargetsThatWereNotLoaded(): void
    {
        $entityManager = $this->entityManager();
        foreach (['ACME' => 'Acme', 'GLOBEX' => 'Globex'] as $code => $name) {
            $owner = new Owner($code, $name);
            $entityManager->persist($owner);
            $entityManager->persist(new Car('car of '.$name, $owner));
        }

        $entityManager->flush();
        $entityManager->clear();

        // the cars are read without their owners
        foreach ($entityManager->getRepository(Car::class)->findAll() as $car) {
            $this->assertInstanceOf(Owner::class, $car->owner);
            $this->assertTrue($entityManager->getUnitOfWork()->isUninitializedObject($car->owner));
            $entityManager->remove($car);
        }

        $entityManager->flush();

        $labels = [];
        foreach ($this->createReader()->createQuery(Car::class)->execute() as $entry) {
            if ('remove' === $entry->type) {
                $labels[] = $entry->getDiffs()['owner']['old']['label'];
            }
        }

        sort($labels);
        $this->assertSame(['Acme', 'Globex'], $labels);
    }

    public function testTheSnapshotKeepsTheIdentifierOfATargetRemovedInTheSameFlush(): void
    {
        $entityManager = $this->entityManager();
        $bundle = new Bundle('bundle');
        new BundleItem($bundle, 'item');
        $entityManager->persist($bundle);
        $entityManager->flush();

        $bundleId = $bundle->getId();

        // the bundle is not audited, and its items are removed along with it
        $entityManager->remove($bundle);
        $entityManager->flush();

        $diffs = $this->lastEntry(BundleItem::class)->getDiffs();
        $this->assertSame([
            'new' => null,
            'old' => ['class' => Bundle::class, 'id' => $bundleId, 'label' => Bundle::class.'#'.$bundleId, 'table' => 'inverse_side_deletion_bundle'],
        ], $diffs['bundle'], 'Doctrine has reset the identifier of the removed bundle: the one captured before the flush is used.');
        $this->assertSame(['new' => null, 'old' => 'item'], $diffs['label']);
    }

    public function testTheSnapshotHonorsTheAllowlist(): void
    {
        $this->provider->getConfiguration()->setEntities([Car::class => ['enabled' => true, 'audited_columns' => ['label']]]);
        $entityManager = $this->entityManager();
        $car = new Car('car', new Owner('ACME', 'Acme'));
        $entityManager->persist($car->owner);
        $entityManager->persist($car);
        $entityManager->flush();

        $entityManager->remove($car);
        $entityManager->flush();

        $this->assertSame(['label'], array_keys($this->lastEntry(Car::class)->getDiffs()));
    }

    private function lastEntry(string $entity): Entry
    {
        $audits = $this->createReader()->createQuery($entity)->execute();
        $this->assertSame('remove', $audits[0]->type);

        return $audits[0];
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Car::class)->getEntityManager();
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration(['full_snapshot_on_remove' => true]));

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/InverseSideDeletion',
            __DIR__.'/../Fixtures/Root',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Car::class => ['enabled' => true],
            BundleItem::class => ['enabled' => true],
        ]);
    }
}
