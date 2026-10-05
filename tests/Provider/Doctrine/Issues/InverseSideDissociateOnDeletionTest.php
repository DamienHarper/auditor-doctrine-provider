<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Model\Entry;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\Bundle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleItem;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests: deleting the owning side of a bidirectional ManyToOne must record a
 * DISSOCIATE entry on the inverse-side owner.
 *
 * Since the fix for issue #310, inverse-side OneToMany collections are skipped by the
 * collection-based hydration and their associate/dissociate entries are derived from the
 * owning side's entity changeset only. Deleted entities have no changeset, so removing a
 * child entity — explicitly or through orphanRemoval — silently dropped the DISSOCIATE
 * entry, while the matching ASSOCIATE entry was recorded on insertion.
 *
 * @see https://github.com/DamienHarper/auditor/issues/310
 *
 * @internal
 */
#[Small]
final class InverseSideDissociateOnDeletionTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testDissociateIsRecordedWhenChildIsRemovedThroughOrphanRemoval(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $bundle->removeItem($item); // orphanRemoval deletes the item on flush
        $em->flush();

        $this->assertEqualsCanonicalizing(
            [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE],
            $this->associationTypes($bundle),
            'bundle_audit must contain a DISSOCIATE entry when an item is removed through orphanRemoval.'
        );
    }

    public function testDissociateIsRecordedWhenChildIsRemovedExplicitly(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $em->remove($item);
        $em->flush();

        $this->assertEqualsCanonicalizing(
            [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE],
            $this->associationTypes($bundle),
            'bundle_audit must contain a DISSOCIATE entry when an item is removed explicitly.'
        );
    }

    public function testOneDissociateIsRecordedPerRemovedChild(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $items = [];
        for ($i = 1; $i <= 3; ++$i) {
            $items[] = new BundleItem($bundle, 'item '.$i);
        }

        $em->persist($bundle);
        $em->flush();

        $bundle->removeItem($items[0]);
        $bundle->removeItem($items[2]);

        $em->flush();

        $types = $this->associationTypes($bundle);

        $this->assertCount(3, array_filter($types, static fn (string $t): bool => TransactionType::ASSOCIATE === $t));
        $this->assertCount(
            2,
            array_filter($types, static fn (string $t): bool => TransactionType::DISSOCIATE === $t),
            'bundle_audit must contain one DISSOCIATE entry per removed item.'
        );
    }

    public function testNoDissociateIsRecordedWhenInverseSideOwnerIsRemovedToo(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $bundleId = $bundle->getId();

        $em->remove($bundle); // cascades to the item
        $em->flush();

        $types = array_map(
            static fn (Entry $e): string => $e->type,
            $this->createReader()->createQuery(Bundle::class, ['object_id' => $bundleId])->execute(),
        );

        $this->assertNotContains(
            TransactionType::DISSOCIATE,
            $types,
            'No DISSOCIATE entry must be written for an owner that is deleted in the same flush.'
        );
        $this->assertContains(TransactionType::REMOVE, $types);
    }

    public function testNoDissociateIsRecordedWhenInverseSideOwnerIsNotAudited(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Bundle::class => ['enabled' => false],
            BundleItem::class => ['enabled' => true],
        ]);

        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $bundle->removeItem($item);
        $em->flush();

        $this->assertSame([], $this->associationTypes($bundle));
    }

    /**
     * @return list<string>
     */
    private function associationTypes(Bundle $bundle): array
    {
        $entries = $this->createReader()->createQuery(Bundle::class, ['object_id' => $bundle->getId()])->execute();

        $types = array_map(static fn (Entry $e): string => $e->type, $entries);

        return array_values(array_filter(
            $types,
            static fn (string $t): bool => \in_array($t, [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE], true),
        ));
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration());

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/InverseSideDeletion',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Bundle::class => ['enabled' => true],
            BundleItem::class => ['enabled' => true],
        ]);
    }
}
