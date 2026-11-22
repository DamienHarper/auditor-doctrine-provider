<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Snapshot;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditInsertBuffer;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\TransactionProcessor;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Snapshot\SnapshotterTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Records the current state of existing entities in their audit table, so that the history of an entity
 * that existed before auditing was enabled starts with its state at that time.
 *
 * Each entity gets an 'insert' entry flagged with "snapshot": true. Entities are read in batches; each batch
 * is written in its own database transaction and shares one transaction id.
 *
 * @see SnapshotterTest
 */
final readonly class Snapshotter
{
    public const int DEFAULT_BATCH_SIZE = 500;

    private TransactionProcessor $processor;

    public function __construct(private DoctrineProvider $provider)
    {
        $this->processor = new TransactionProcessor($provider);
    }

    /**
     * Returns the number of rows of $entity (subclasses included), regardless of their audit history.
     */
    public function count(string $entity): int
    {
        $this->validate($entity);
        $entityManager = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager();

        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($entity, 'e')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Snapshots every existing $entity (subclasses included).
     *
     * Entities that already have an audit entry are skipped unless $force is true. With $dryRun, nothing is
     * written and the result tells how many entities would be snapshotted.
     *
     * The entity manager is cleared after each batch: run it in a dedicated process (e.g. a console command).
     *
     * @param positive-int                                     $batchSize
     * @param null|callable(int $read, int $snapshotted): void $onBatch   called after each batch with its own counts
     *
     * @throws InvalidArgumentException when $entity is not audited, has no audit table or has a composite identifier
     */
    public function snapshot(string $entity, int $batchSize = self::DEFAULT_BATCH_SIZE, bool $force = false, bool $dryRun = false, ?callable $onBatch = null): SnapshotResult
    {
        $this->validate($entity);

        $entityManager = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager();
        $storageConnection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $meta = $entityManager->getClassMetadata($entity);
        $idField = $meta->getSingleIdentifierFieldName();

        // Keyset pagination when the identifier is a plain field, offset pagination otherwise
        // (identifier through a to-one association).
        $keyset = $meta->hasField($idField);
        $last = null;
        $offset = 0;
        $read = 0;
        $snapshotted = 0;

        do {
            $queryBuilder = $entityManager->createQueryBuilder()
                ->select('e')
                ->from($entity, 'e')
                ->setMaxResults($batchSize)
            ;

            if ($keyset) {
                $queryBuilder->orderBy('e.'.$idField, 'ASC');
                if (null !== $last) {
                    $queryBuilder
                        ->where('e.'.$idField.' > :last')
                        ->setParameter('last', $last, $meta->getTypeOfField($idField))
                    ;
                }
            } else {
                $queryBuilder
                    ->addSelect('IDENTITY(e.'.$idField.') AS HIDDEN snapshot_id')
                    ->orderBy('snapshot_id', 'ASC')
                    ->setFirstResult($offset)
                ;
            }

            /** @var list<object> $entities */
            $entities = $queryBuilder->getQuery()->getResult();
            $batchRead = \count($entities);
            if (0 === $batchRead) {
                break;
            }

            $last = DoctrineHelper::getReflectionPropertyValue($meta, $idField, $entities[$batchRead - 1]);
            $offset += $batchRead;

            $transactionId = (string) new Ulid();
            $batchSnapshotted = $dryRun
                ? $this->processor->snapshot($entityManager, $entities, $transactionId, $force, true)
                : (int) $storageConnection->transactional(
                    fn (): int => $this->write($entityManager, $entities, $transactionId, $force, $batchSize)
                );

            $entityManager->clear();

            $read += $batchRead;
            $snapshotted += $batchSnapshotted;
            if (null !== $onBatch) {
                $onBatch($batchRead, $batchSnapshotted);
            }
        } while ($batchRead === $batchSize);

        return new SnapshotResult($read, $snapshotted);
    }

    /**
     * Writes the snapshot entries of a batch with multi-row INSERT statements, whatever the `batch_size` option.
     *
     * @param list<object> $entities
     * @param positive-int $batchSize
     */
    private function write(EntityManagerInterface $entityManager, array $entities, string $transactionId, bool $force, int $batchSize): int
    {
        $buffer = new AuditInsertBuffer($batchSize);
        $previous = $this->provider->useInsertBuffer($buffer);

        try {
            $count = $this->processor->snapshot($entityManager, $entities, $transactionId, $force);
        } finally {
            $this->provider->useInsertBuffer($previous);
        }

        $buffer->flush();

        return $count;
    }

    private function validate(string $entity): void
    {
        if (!$this->provider->isAudited($entity)) {
            throw new InvalidArgumentException(\sprintf('Entity "%s" is not audited (unknown entity or auditing disabled).', $entity));
        }

        if (!$this->provider->hasAuditTable($entity)) {
            throw new InvalidArgumentException(\sprintf('Entity "%s" has no audit table.', $entity));
        }

        $meta = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);
        if ($meta->isIdentifierComposite) {
            throw new InvalidArgumentException(\sprintf('Entity "%s" has a composite identifier: composite primary keys are not supported.', $entity));
        }
    }
}
