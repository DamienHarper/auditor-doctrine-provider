<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Model\TransactionInterface;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Transaction\TransactionHydratorTest;
use DH\Auditor\Transaction\TransactionHydratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;

/**
 * @see TransactionHydratorTest
 */
final class TransactionHydrator implements TransactionHydratorInterface
{
    use AuditTrait;

    public function __construct(private DoctrineProvider $provider) {}

    /**
     * @param Transaction $transaction
     */
    public function hydrate(TransactionInterface $transaction): void
    {
        $em = $transaction->getEntityManager();
        $this->hydrateWithScheduledInsertions($transaction, $em);
        $this->hydrateWithScheduledUpdates($transaction, $em);
        $this->hydrateWithScheduledDeletions($transaction, $em);
        $this->hydrateWithScheduledCollectionUpdates($transaction, $em);
        $this->hydrateWithScheduledCollectionDeletions($transaction, $em);
        $this->hydrateInverseSideAssociations($transaction, $em);
        $this->hydrateInverseSideDissociationsOfDeletedEntities($transaction, $em);
    }

    private function hydrateWithScheduledInsertions(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();
        $entities = array_values($uow->getScheduledEntityInsertions());
        for ($i = \count($entities) - 1; $i >= 0; --$i) {
            $entity = $entities[$i];
            if ($this->provider->isAudited($entity)) {
                $transaction->insert(
                    $entity,
                    $uow->getEntityChangeSet($entity),
                );
            }
        }
    }

    private function hydrateWithScheduledUpdates(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();
        $entities = array_values($uow->getScheduledEntityUpdates());
        for ($i = \count($entities) - 1; $i >= 0; --$i) {
            $entity = $entities[$i];
            if ($this->provider->isAudited($entity)) {
                $transaction->update(
                    $entity,
                    $uow->getEntityChangeSet($entity),
                );
            }
        }
    }

    private function hydrateWithScheduledDeletions(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();
        $entities = array_values($uow->getScheduledEntityDeletions());
        for ($i = \count($entities) - 1; $i >= 0; --$i) {
            $entity = $entities[$i];
            if ($this->provider->isAudited($entity)) {
                $uow->initializeObject($entity);
                $transaction->remove(
                    $entity,
                    $this->id($entityManager, $entity),
                );
            }
        }
    }

    private function hydrateWithScheduledCollectionUpdates(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();

        /** @var array<PersistentCollection> $collections */
        $collections = array_values($uow->getScheduledCollectionUpdates());
        for ($i = \count($collections) - 1; $i >= 0; --$i) {
            $collection = $collections[$i];
            $owner = $collection->getOwner();

            if (null !== $owner && $this->provider->isAudited($owner)) {
                $collectionMapping = $collection->getMapping();

                // Skip inverse-side (mappedBy) OneToMany collections — Doctrine's UoW does not
                // reliably schedule them when the FK is changed through the owning-side ManyToOne
                // field. When multiple entities are flushed in a single call, some or all associate
                // entries may be silently dropped. Association changes for these collections are
                // always covered by hydrateInverseSideAssociations(), which scans the owning-side
                // entity changeset instead and is reliable in all cases.
                // ManyToMany inverse collections are kept: their join-table changes are driven by
                // the owning side and Doctrine does schedule them reliably.
                // @see https://github.com/DamienHarper/auditor/issues/310
                if (!$collectionMapping->isOwningSide() && !$collectionMapping->isManyToMany()) {
                    continue;
                }

                $mapping = $collectionMapping->toArray();

                // The audit entry is written to the owner's audit table, so we only require
                // the owner to be audited. Requiring the target to also be audited silently
                // dropped associations involving non-audited target entities, breaking
                // unidirectional ManyToMany relations where only the owning side has
                // the #[Auditable] attribute.
                // @see https://github.com/DamienHarper/auditor/issues/234

                /** @var object $entity */
                foreach ($collection->getInsertDiff() as $entity) {
                    $transaction->associate(
                        $owner,
                        $entity,
                        $mapping,
                    );
                }

                /** @var object $entity */
                foreach ($collection->getDeleteDiff() as $entity) {
                    $this->dissociate($transaction, $entityManager, $owner, $entity, $mapping);
                }
            }
        }
    }

    private function hydrateWithScheduledCollectionDeletions(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();

        /** @var array<PersistentCollection> $collections */
        $collections = array_values($uow->getScheduledCollectionDeletions());
        for ($i = \count($collections) - 1; $i >= 0; --$i) {
            $collection = $collections[$i];
            $owner = $collection->getOwner();

            if (null !== $owner && $this->provider->isAudited($owner)) {
                $collectionMapping = $collection->getMapping();

                // Same rationale as hydrateWithScheduledCollectionUpdates(): skip inverse-side
                // OneToMany collections — their dissociate entries are covered by
                // hydrateInverseSideAssociations() via the owning-side entity changeset.
                // @see https://github.com/DamienHarper/auditor/issues/310
                if (!$collectionMapping->isOwningSide() && !$collectionMapping->isManyToMany()) {
                    continue;
                }

                $mapping = $collectionMapping->toArray();

                /** @var object $entity */
                foreach ($this->deletedCollectionElements($entityManager, $collection) as $entity) {
                    $this->dissociate($transaction, $entityManager, $owner, $entity, $mapping);
                }
            }
        }
    }

    /**
     * Returns the elements whose association rows the scheduled deletion of $collection removes.
     *
     * A collection replaced by another one is de-referenced untouched: it still holds its
     * elements (loaded on access if it was not initialized). A collection emptied in place with
     * PersistentCollection::clear() is still the owner's one, but clear() drops its elements
     * (without even loading them when the collection was not initialized) and takes a new,
     * empty snapshot: neither toArray(), getSnapshot() nor getDeleteDiff() returns them anymore.
     * They are read from the join table instead, which onFlush sees before the DELETE runs.
     *
     * @return array<object>
     */
    private function deletedCollectionElements(EntityManagerInterface $entityManager, PersistentCollection $collection): array
    {
        $owner = $collection->getOwner();
        $collectionMapping = $collection->getMapping();

        if (
            null === $owner
            || !$collectionMapping->isManyToMany()
            || !$collectionMapping->isOwningSide()
            || $entityManager->getUnitOfWork()->isScheduledForInsert($owner)
            || $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($owner))->getFieldValue($owner, $collectionMapping->fieldName) !== $collection
        ) {
            return $collection->toArray();
        }

        return $entityManager->getUnitOfWork()->getCollectionPersister($collectionMapping)->slice($collection, 0);
    }

    /**
     * Derives associate/dissociate audit entries for bidirectional single-valued associations
     * by scanning the owning side's entity changeset.
     *
     * For bidirectional ManyToOne associations this is the primary audit path: the collection
     * path (hydrateWithScheduledCollectionUpdates/Deletions) unconditionally skips inverse-side
     * OneToMany collections because Doctrine's UoW schedules them unreliably — when multiple
     * entities are flushed together and all set the same ManyToOne target, the collection update
     * may be scheduled only once and getInsertDiff() may return an incomplete result.
     *
     * For bidirectional OneToOne owning-side associations this is the only path: single-valued
     * associations never appear in the scheduled collection queues.
     *
     * This method scans every inserted/updated entity's changeset for owning-side single-valued
     * association changes (ManyToOne, OneToOne owning) that declare an inversedBy, and emits
     * the corresponding associate/dissociate on the inverse-side owner.
     *
     * Deleted entities have no changeset: they are dissociated from the inverse-side owner they
     * were originally attached to by hydrateInverseSideDissociationsOfDeletedEntities().
     *
     * @see https://github.com/DamienHarper/auditor/issues/310
     */
    private function hydrateInverseSideAssociations(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();

        $entities = array_merge(
            array_values($uow->getScheduledEntityInsertions()),
            array_values($uow->getScheduledEntityUpdates()),
        );

        foreach ($entities as $entity) {
            $changeset = $uow->getEntityChangeSet($entity);
            if ([] === $changeset) {
                continue;
            }

            $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));

            foreach ($changeset as $fieldName => [$old, $new]) {
                if (!$meta->hasAssociation($fieldName) || !$meta->isSingleValuedAssociation($fieldName)) {
                    continue; // Not an association, or a collection-valued one.
                }

                $assocMapping = $meta->getAssociationMapping($fieldName);

                // Only owning-side associations that declare an inverse side are relevant here.
                // After this check PHPStan narrows $assocMapping to OwningSideMapping, which
                // carries the inversedBy property accessed below.
                if (!$assocMapping->isOwningSide()) {
                    continue;
                }

                $inversedBy = $assocMapping->inversedBy;

                if (null === $inversedBy || '' === $inversedBy) {
                    continue; // Unidirectional association (no inversedBy declared): nothing to audit on the inverse side.
                }

                $targetMeta = $entityManager->getClassMetadata($assocMapping->targetEntity);
                $inverseMapping = $targetMeta->getAssociationMapping($inversedBy)->toArray();

                // Dissociate from the old target when the association is removed or re-assigned.
                if (null !== $old && $this->provider->isAudited($old)) {
                    $this->dissociate($transaction, $entityManager, $old, $entity, $inverseMapping);
                }

                // Associate with the new target when the association is set or re-assigned.
                if (null !== $new && $this->provider->isAudited($new)) {
                    $transaction->associate($new, $entity, $inverseMapping);
                }
            }
        }
    }

    /**
     * Dissociates every deleted entity from the inverse-side owner of its bidirectional owning-side
     * single-valued associations (ManyToOne, OneToOne owning), unless that owner is deleted in the
     * same flush. Deleting an entity — explicitly or through orphanRemoval — is the last way to
     * break such an association, and it never shows up in an entity changeset.
     */
    private function hydrateInverseSideDissociationsOfDeletedEntities(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
            $originalData = null;

            foreach ($meta->associationMappings as $fieldName => $assocMapping) {
                if (!$assocMapping->isToOneOwningSide()) {
                    continue;
                }

                $inversedBy = $assocMapping->inversedBy;
                if (null === $inversedBy || '' === $inversedBy) {
                    continue; // Unidirectional association: nothing to audit on the inverse side.
                }

                $targetMeta = $entityManager->getClassMetadata($assocMapping->targetEntity);
                if (!$this->isAuditedClassOrSubclass($targetMeta)) {
                    continue; // The inverse-side owner can never be audited: avoid loading the entity.
                }

                if (null === $originalData) {
                    // An entity deleted through a reference (EntityManager::getReference()) is an
                    // uninitialized proxy whose original data is still empty: load it to know which
                    // owner it was attached to. Audited deleted entities are already initialized by
                    // hydrateWithScheduledDeletions().
                    $uow->initializeObject($entity);
                    $originalData = $uow->getOriginalEntityData($entity);
                }

                $old = $originalData[$fieldName] ?? null;
                if (null === $old || $uow->isScheduledForDelete($old) || !$this->provider->isAudited($old)) {
                    continue;
                }

                $this->dissociate($transaction, $entityManager, $old, $entity, $targetMeta->getAssociationMapping($inversedBy)->toArray());
            }
        }
    }

    /**
     * Records a dissociation. When the target is deleted in the same flush, its identifier is
     * captured now: Doctrine resets the generated identifier of a deleted entity once the DELETE
     * statement is executed, i.e. before the audit entry is written.
     *
     * @param array<string, mixed> $mapping
     */
    private function dissociate(Transaction $transaction, EntityManagerInterface $entityManager, object $source, object $target, array $mapping): void
    {
        $uow = $entityManager->getUnitOfWork();
        if ($uow->isScheduledForDelete($target)) {
            $targetMeta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($target));

            // A composite key is flattened into one string, as the summary of a composite-key
            // association target is; Doctrine also forgets that key once the entity is deleted.
            $transaction->rememberRemovedId($target, $targetMeta->isIdentifierComposite
                ? implode('-', $uow->getEntityIdentifier($target))
                : $this->id($entityManager, $target, $targetMeta));
        }

        $transaction->dissociate($source, $target, $mapping);
    }

    /**
     * @param ClassMetadata<object> $meta
     */
    private function isAuditedClassOrSubclass(ClassMetadata $meta): bool
    {
        return array_any([$meta->name, ...$meta->subClasses], fn (string $class): bool => $this->provider->isAudited($class));
    }
}
