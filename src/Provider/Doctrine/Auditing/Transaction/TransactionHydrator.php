<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Model\TransactionInterface;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Transaction\TransactionHydratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

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
                // entries may be silently dropped. Association changes for these inverse-side
                // collections are handled in hydrateInverseSideAssociations() by scanning the
                // owning-side entity changeset instead, which Doctrine always tracks reliably.
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
                    $transaction->dissociate(
                        $owner,
                        $entity,
                        $mapping,
                    );
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
                $mapping = $collection->getMapping()->toArray();

                /** @var object $entity */
                foreach ($collection->toArray() as $entity) {
                    $transaction->dissociate(
                        $owner,
                        $entity,
                        $mapping,
                    );
                }
            }
        }
    }

    /**
     * Derives associate/dissociate audit entries from the owning side's entity changeset for
     * bidirectional ManyToOne associations.
     *
     * Doctrine's UoW schedules inverse-side (OneToMany/mappedBy) collections for collection
     * updates unreliably: when multiple entities are flushed together and all set the same
     * ManyToOne target, the collection update may be scheduled only once and getInsertDiff()
     * may return an incomplete result. This method supplements collection-based detection by
     * scanning every inserted/updated entity's changeset for owning-side single-valued
     * association changes (ManyToOne, OneToOne owning) that have a declared inversedBy, and
     * emits the corresponding associate/dissociate on the inverse-side owner.
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

                // Only handle owning-side associations that declare an inverse side.
                // isOwningSide() acts as a PHPStan assertion: $assocMapping is OwningSideMapping.
                if (!$assocMapping->isOwningSide()) {
                    continue;
                }

                $inversedBy = $assocMapping->inversedBy;

                if (null === $inversedBy || '' === $inversedBy) {
                    continue; // Unidirectional association: no inverse collection to audit.
                }

                $targetMeta = $entityManager->getClassMetadata($assocMapping->targetEntity);
                $inverseMapping = $targetMeta->getAssociationMapping($inversedBy)->toArray();

                // Dissociate from the old target when the association is removed or re-assigned.
                if (null !== $old && $this->provider->isAudited($old)) {
                    $transaction->dissociate($old, $entity, $inverseMapping);
                }

                // Associate with the new target when the association is set or re-assigned.
                if (null !== $new && $this->provider->isAudited($new)) {
                    $transaction->associate($new, $entity, $inverseMapping);
                }
            }
        }
    }
}
