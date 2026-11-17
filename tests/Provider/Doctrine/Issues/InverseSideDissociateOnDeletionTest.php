<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Model\Entry;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\Bundle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleCover;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleTag;
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
 * The DISSOCIATE entry must also identify the deleted entity: Doctrine resets the generated
 * identifier of a deleted entity before the audit entries are written, so it is captured at
 * hydration time (this also applies to ManyToMany dissociations of a deleted entity).
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

        $itemId = $item->getId();

        $bundle->removeItem($item); // orphanRemoval deletes the item on flush
        $em->flush();

        $this->assertEqualsCanonicalizing(
            [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE],
            $this->associationTypes($bundle),
            'bundle_audit must contain a DISSOCIATE entry when an item is removed through orphanRemoval.'
        );
        $this->assertDissociatedTargets([[BundleItem::class, $itemId, 'bundle']], $bundle);
    }

    public function testDissociateIsRecordedWhenChildIsRemovedExplicitly(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $itemId = $item->getId();

        $em->remove($item);
        $em->flush();

        $this->assertEqualsCanonicalizing(
            [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE],
            $this->associationTypes($bundle),
            'bundle_audit must contain a DISSOCIATE entry when an item is removed explicitly.'
        );
        $this->assertDissociatedTargets([[BundleItem::class, $itemId, 'bundle']], $bundle);
    }

    public function testDissociateIsRecordedWhenChildIsRemovedThroughAnUninitializedReference(): void
    {
        // The item is not audited, so nothing initializes the reference before the inverse-side scan.
        $this->provider->getConfiguration()->setEntities([
            Bundle::class => ['enabled' => true],
            BundleItem::class => ['enabled' => false],
        ]);

        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $bundleId = $bundle->getId();
        $itemId = $item->getId();
        $em->clear();

        $em->remove($em->getReference(BundleItem::class, $itemId));
        $em->flush();

        $bundle = $em->find(Bundle::class, $bundleId);
        $this->assertInstanceOf(Bundle::class, $bundle);
        $this->assertDissociatedTargets([[BundleItem::class, $itemId, 'bundle']], $bundle);
    }

    public function testDissociateIsRecordedWhenOneToOneOwningSideIsRemoved(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $cover = new BundleCover($bundle);
        $em->persist($bundle);
        $em->persist($cover);
        $em->flush();

        $coverId = $cover->getId();

        $em->remove($cover);
        $bundle->setCover(null);
        $em->flush();

        $this->assertEqualsCanonicalizing(
            [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE],
            $this->associationTypes($bundle),
            'bundle_audit must contain a DISSOCIATE entry when the owning side of a OneToOne is removed.'
        );
        $this->assertDissociatedTargets([[BundleCover::class, $coverId, 'bundle']], $bundle);
    }

    public function testManyToManyDissociateIdentifiesATargetRemovedInTheSameFlush(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $tag = new BundleTag();
        $bundle->addTag($tag);
        $em->persist($bundle);
        $em->persist($tag);
        $em->flush();

        $tagId = $tag->getId();

        $bundle->removeTag($tag);
        $em->remove($tag);
        $em->flush();

        $this->assertDissociatedTargets([[BundleTag::class, $tagId, 'bundles']], $bundle);
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

        $removedIds = [$items[0]->getId(), $items[2]->getId()];

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
        $this->assertDissociatedTargets([
            [BundleItem::class, $removedIds[0], 'bundle'],
            [BundleItem::class, $removedIds[1], 'bundle'],
        ], $bundle);
    }

    public function testManyToManyDissociateIdentifiesATargetRemovedWhenTheCollectionIsReplaced(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();

        $bundle = new Bundle('bundle');
        $kept = new BundleTag();
        $removed = new BundleTag();
        $bundle->addTag($kept);
        $bundle->addTag($removed);
        $em->persist($bundle);
        $em->persist($kept);
        $em->persist($removed);
        $em->flush();

        $keptId = $kept->getId();
        $removedId = $removed->getId();

        // Replacing an owning-side collection schedules the deletion of the previous one.
        $bundle->replaceTagsWithEmptyCollection();
        $em->remove($removed);
        $em->flush();

        $this->assertDissociatedTargets([
            [BundleTag::class, $keptId, 'bundles'],
            [BundleTag::class, $removedId, 'bundles'],
        ], $bundle);
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

    /**
     * @param list<array{class-string, int, string}> $expected [class, id, field] of each dissociated target
     */
    private function assertDissociatedTargets(array $expected, Bundle $bundle): void
    {
        $entries = $this->createReader()->createQuery(Bundle::class, [
            'object_id' => $bundle->getId(),
            'type' => TransactionType::DISSOCIATE,
        ])->execute();

        $actual = array_map(static function (Entry $entry): array {
            $target = $entry->getDiffs()['target'] ?? [];

            return [$target['class'] ?? null, $target['id'] ?? null, $target['field'] ?? null, $target['label'] ?? null];
        }, $entries);

        $expected = array_map(
            static fn (array $target): array => [$target[0], $target[1], $target[2], $target[0].'#'.$target[1]],
            $expected,
        );

        $this->assertEqualsCanonicalizing($expected, $actual, 'Each DISSOCIATE entry must identify the removed entity.');
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
            BundleCover::class => ['enabled' => true],
            BundleTag::class => ['enabled' => true],
        ]);
    }
}
