<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Model\TransactionInterface;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Transaction\TransactionProcessorTest;
use DH\Auditor\Transaction\TransactionProcessorInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @see TransactionProcessorTest
 */
final class TransactionProcessor implements TransactionProcessorInterface
{
    use AuditTrait;

    private ?\DateTimeZone $dateTimeZone = null;

    // Per-flush context — computed once in process(), consumed in audit()
    private ?string $flushCreatedAt = null;

    private ?string $flushBlameId = null;

    private ?string $flushBlameJson = null;

    private ?string $flushExtraData = null;

    public function __construct(private DoctrineProvider $provider) {}

    /**
     * @param Transaction $transaction
     */
    public function process(TransactionInterface $transaction): void
    {
        $em = $transaction->getEntityManager();
        $blameContext = $transaction->getBlameContext();
        $this->preComputeFlushContext($this->blame($blameContext?->user), $blameContext);
        $this->processInsertions($transaction, $em);
        $this->processUpdates($transaction, $em);
        $this->processAssociations($transaction, $em);
        $this->processDissociations($transaction, $em);
        $this->processDeletions($transaction, $em);
    }

    /**
     * Writes a snapshot of existing entities: one 'insert' entry per entity, flagged with "snapshot": true,
     * that records the current state of the entity (old: null, new: current value).
     *
     * Entities that already have an audit entry are skipped unless $force is true; with $dryRun, nothing is
     * written. Returns the number of entities snapshotted (or that would be, with $dryRun).
     *
     * @param list<object> $entities
     */
    public function snapshot(EntityManagerInterface $entityManager, array $entities, string $transactionId, bool $force = false, bool $dryRun = false): int
    {
        $byClass = [];
        foreach ($entities as $entity) {
            $class = DoctrineHelper::getRealClassName($entity);
            if ($this->provider->isAudited($class) && $this->provider->hasAuditTable($class)) {
                $byClass[$class][] = $entity;
            }
        }

        if (!$dryRun) {
            // written right away, outside of any flush: the current blame context applies
            $blameContext = $this->provider->getAuditor()->getConfiguration()->getBlameContextStack()->current();
            $this->preComputeFlushContext($this->blame($blameContext?->user), $blameContext);
        }

        $count = 0;
        foreach ($byClass as $class => $classEntities) {
            $meta = $entityManager->getClassMetadata($class);
            $ids = [];
            foreach ($classEntities as $entity) {
                $ids[spl_object_id($entity)] = (string) $this->id($entityManager, $entity, $meta); // @phpstan-ignore-line
            }

            $audited = $force ? [] : $this->auditedObjectIds($class, array_values($ids));
            if (!$dryRun) {
                $this->loadToOneTargets($entityManager, $meta, $classEntities);
            }

            foreach ($classEntities as $entity) {
                $id = $ids[spl_object_id($entity)];
                if (isset($audited[$id])) {
                    continue;
                }

                ++$count;
                if ($dryRun) {
                    continue;
                }

                $diff = $this->diff($entityManager, $entity, $this->snapshotChangeset($meta, $entity), $meta);
                $diff['snapshot'] = true;

                $this->audit([
                    'action' => TransactionType::Insert,
                    'diff' => $diff,
                    'table' => $meta->getTableName(),
                    'schema' => $meta->getSchemaName(),
                    'id' => $id,
                    'transaction_id' => $transactionId,
                    'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
                    'entity' => $meta->getName(),
                    'entity_object' => $entity,
                ]);
            }
        }

        return $count;
    }

    /**
     * Pre-computes blame JSON, extra data and timestamp once for the entire flush.
     * The blame context, if any, is written in the `via` key of the blame JSON.
     * Avoids repeated json_encode(), extra data provider calls and DateTimeImmutable creation per audit entry.
     *
     * @param array{client_ip: ?string, user_firewall: ?string, user_fqdn: ?string, user_id: ?string, username: ?string} $blame
     */
    private function preComputeFlushContext(array $blame, ?BlameContext $blameContext = null): void
    {
        $tz = $this->dateTimeZone ??= new \DateTimeZone($this->provider->getAuditor()->getConfiguration()->timezone);
        // the extra data provider takes no argument: called once for the whole flush, not for every entry
        $this->flushExtraData = $this->extraData();
        $this->flushCreatedAt = new \DateTimeImmutable('now', $tz)->format('Y-m-d H:i:s.u');
        $this->flushBlameId = $blame['user_id'];
        $hasBlame = null !== $blame['user_id']
            || null !== $blame['username']
            || null !== $blame['user_fqdn']
            || null !== $blame['user_firewall']
            || null !== $blame['client_ip'];

        if (!$hasBlame && !$blameContext instanceof BlameContext) {
            $this->flushBlameJson = null;

            return;
        }

        $blameData = [
            'username' => $blame['username'],
            'user_fqdn' => $blame['user_fqdn'],
            'user_firewall' => $blame['user_firewall'],
            'ip' => $blame['client_ip'],
        ];

        if ($blameContext instanceof BlameContext) {
            $blameData['via'] = $blameContext->toArray();
        }

        $this->flushBlameJson = json_encode($blameData, JSON_THROW_ON_ERROR);
    }

    private function notify(array $payload, ?object $entity = null): void
    {
        $dispatcher = $this->provider->getAuditor()->getEventDispatcher();
        $dispatcher->dispatch(new LifecycleEvent($payload, $entity));
    }

    /**
     * Adds an insert entry to the audit table.
     */
    private function insert(EntityManagerInterface $entityManager, object $entity, array $ch, string $transactionId): void
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => TransactionType::Insert,
            'diff' => $this->diff($entityManager, $entity, $ch, $meta),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($entityManager, $entity, $meta),
            'transaction_id' => $transactionId,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
            'entity_object' => $entity,
        ]);
    }

    /**
     * Adds an update entry to the audit table.
     */
    private function update(EntityManagerInterface $entityManager, object $entity, array $ch, string $transactionId): void
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $diff = $this->diff($entityManager, $entity, $ch, $meta);

        if ([] === $diff['changes']) {
            return; // if there is no entity diff, do not log it
        }

        $this->audit([
            'action' => TransactionType::Update,
            'diff' => $diff,
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($entityManager, $entity, $meta),
            'transaction_id' => $transactionId,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
            'entity_object' => $entity,
        ]);
    }

    /**
     * Adds a remove entry to the audit table.
     *
     * When full_snapshot_on_remove is enabled in configuration, captures all audited fields
     * before deletion. Otherwise records only the entity identity (id, class, label, table).
     */
    private function remove(EntityManagerInterface $entityManager, object $entity, mixed $id, string $transactionId): void
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        if ($configuration->isFullSnapshotOnRemoveEnabled()) {
            $diff = $this->removalSnapshot($entityManager, $entity, $meta);
        } else {
            $diff = [
                'source' => $this->summarize($entityManager, $entity, ['id' => $id], $meta),
                'changes' => [],
            ];
        }

        $this->audit([
            'action' => TransactionType::Remove,
            'diff' => $diff,
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $id,
            'transaction_id' => $transactionId,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
            'entity_object' => $entity,
        ]);
    }

    /**
     * Adds an association entry to the audit table.
     */
    private function associate(EntityManagerInterface $entityManager, object $source, object $target, array $mapping, string $transactionId): void
    {
        $this->associateOrDissociate(TransactionType::Associate, $entityManager, $source, $target, $mapping, $transactionId);
    }

    /**
     * Adds a dissociation entry to the audit table.
     *
     * $targetId is the identifier captured at hydration time when the target is deleted in the
     * same flush (Doctrine resets it once the DELETE statement has run).
     */
    private function dissociate(EntityManagerInterface $entityManager, object $source, object $target, array $mapping, string $transactionId, mixed $targetId = null): void
    {
        $this->associateOrDissociate(TransactionType::Dissociate, $entityManager, $source, $target, $mapping, $transactionId, $targetId);
    }

    private function processInsertions(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();
        foreach ($transaction->getInserted() as $dto) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($dto->getChangeset(), $uow->getEntityChangeSet($dto->source));
            $this->insert($entityManager, $dto->source, $ch, $transaction->getTransactionId());
        }
    }

    private function processUpdates(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        $uow = $entityManager->getUnitOfWork();
        foreach ($transaction->getUpdated() as $dto) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($dto->getChangeset(), $uow->getEntityChangeSet($dto->source));
            $this->update($entityManager, $dto->source, $ch, $transaction->getTransactionId());
        }
    }

    private function processAssociations(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        foreach ($transaction->getAssociated() as $dto) {
            $this->associate($entityManager, $dto->source, $dto->getTarget(), $dto->getMapping(), $transaction->getTransactionId());
        }
    }

    private function processDissociations(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        foreach ($transaction->getDissociated() as $dto) {
            $target = $dto->getTarget();
            $this->dissociate($entityManager, $dto->source, $target, $dto->getMapping(), $transaction->getTransactionId(), $transaction->getRemovedId($target));
        }
    }

    private function processDeletions(Transaction $transaction, EntityManagerInterface $entityManager): void
    {
        foreach ($transaction->getRemoved() as $dto) {
            $this->remove($entityManager, $dto->source, $dto->getId(), $transaction->getTransactionId());
        }
    }

    /**
     * Adds an association entry to the audit table.
     */
    private function associateOrDissociate(TransactionType $type, EntityManagerInterface $entityManager, object $source, object $target, array $mapping, string $transactionId, mixed $targetId = null): void
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($source));
        $data = [
            'action' => $type,
            'diff' => [
                'source' => $this->summarize($entityManager, $source, ['field' => $mapping['fieldName']], $meta),
                'target' => $this->summarize($entityManager, $target, ['field' => $mapping['isOwningSide'] ? ($mapping['inversedBy'] ?? null) : ($mapping['mappedBy'] ?? null)], null, $targetId),
                'is_owning_side' => $mapping['isOwningSide'],
            ],
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($entityManager, $source, $meta),
            'transaction_id' => $transactionId,
            'discriminator' => $this->getDiscriminator($source, $meta->inheritanceType),
            'entity' => $meta->getName(),
            'entity_object' => $source,
        ];

        if (isset($mapping['joinTable']['name'])) {
            // 'join_table' replaces the old 'table' key for ManyToMany join table name
            $data['diff']['join_table'] = $mapping['joinTable']['name'];
        }

        $this->audit($data);
    }

    /**
     * Adds an entry to the audit table.
     *
     * @param array{action: TransactionType, diff: mixed, table: string, schema: ?string, id: mixed, transaction_id: string, discriminator: ?string, entity: string, entity_object: ?object} $data
     */
    private function audit(array $data): void
    {
        $entityObject = $data['entity_object'] ?? null;
        $entityClass = $data['entity'];

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        // The cache correctly handles quoted identifiers for computed_audit_table_name.
        $auditTable = $this->entityConfig($entityClass)['computed_audit_table_name'];
        $diff = $data['diff'];
        if ((\is_string($diff) || \is_array($diff)) && $configuration->isUtf8ConvertEnabled()) {
            $diff = $this->convertEncoding($diff);
        }

        $payload = [
            'entity' => $data['entity'],
            'table' => $auditTable,
            'schema_version' => 2,
            'type' => $data['action']->value,
            'object_id' => (string) $data['id'],
            'discriminator' => $data['discriminator'],
            'transaction_id' => $data['transaction_id'],
            'diffs' => json_encode($diff, JSON_THROW_ON_ERROR),
            'extra_data' => $this->flushExtraData,
            'blame_id' => $this->flushBlameId,
            'blame' => $this->flushBlameJson,
            'created_at' => $this->flushCreatedAt,
        ];

        // send an `AuditEvent` event
        $this->notify($payload, $entityObject);
    }

    // Avoid warning (and dismissal) of objects in input array when using mb_convert_encoding
    private function convertEncoding(mixed $input): mixed
    {
        if (\is_string($input)) {
            return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        }

        if (\is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$this->convertEncoding($key)] = $this->convertEncoding($value); // inbuilt mb_convert_encoding also converts keys
            }
        }

        // Leave any other thing as is
        return $input;
    }

    /**
     * Builds the changeset of an insert from the current state of an entity: every field and owning-side
     * to-one association, as [null, current value]. Generated identifiers are left out, as in a real insert.
     *
     * @return array<string, array{0: null, 1: mixed}>
     */
    private function snapshotChangeset(ClassMetadata $meta, object $entity): array
    {
        $changeset = [];
        foreach (array_keys($meta->fieldMappings) as $fieldName) {
            if ($meta->isIdentifier($fieldName) && !$meta->isIdentifierNatural()) {
                continue;
            }

            $changeset[$fieldName] = [null, DoctrineHelper::getReflectionPropertyValue($meta, $fieldName, $entity)];
        }

        foreach (array_keys($meta->associationMappings) as $fieldName) {
            if ($meta->isSingleValuedAssociation($fieldName) && !$meta->isAssociationInverseSide($fieldName)) {
                $changeset[$fieldName] = [null, DoctrineHelper::getReflectionPropertyValue($meta, $fieldName, $entity)];
            }
        }

        return $changeset;
    }

    /**
     * Loads, with one query per target class, the uninitialized targets of the owning-side to-one associations of
     * $entities whose class has a __toString() method: their summary label would otherwise load them one by one.
     *
     * @param ClassMetadata<object> $meta
     * @param list<object>          $entities
     */
    private function loadToOneTargets(EntityManagerInterface $entityManager, ClassMetadata $meta, array $entities): void
    {
        $uow = $entityManager->getUnitOfWork();

        /** @var array<class-string, array<string, mixed>> $ids identifiers by target class */
        $ids = [];
        foreach ($meta->associationMappings as $fieldName => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }

            foreach ($entities as $entity) {
                $target = DoctrineHelper::getReflectionPropertyValue($meta, $fieldName, $entity);
                if (!\is_object($target) || !$uow->isUninitializedObject($target)) {
                    continue;
                }

                $targetClass = DoctrineHelper::getRealClassName($target);
                if (!(self::$hasToStringCache[$targetClass] ??= method_exists($targetClass, '__toString'))) {
                    continue;
                }

                $identifier = $uow->getEntityIdentifier($target);
                if (1 === \count($identifier)) {
                    $id = reset($identifier);
                    $ids[$targetClass][\is_scalar($id) || $id instanceof \Stringable ? (string) $id : spl_object_id($target)] = $id;
                }
            }
        }

        foreach ($ids as $targetClass => $targetIds) {
            $targetMeta = $entityManager->getClassMetadata($targetClass);
            if ($targetMeta->isIdentifierComposite) {
                continue;
            }

            // the targets are hydrated into the proxies held by $entities
            $entityManager->getRepository($targetMeta->getName())->findBy([$targetMeta->getSingleIdentifierFieldName() => array_values($targetIds)]);
        }
    }

    /**
     * Returns, among $objectIds, those that already have an entry in the audit table of $entity.
     *
     * @param list<string> $objectIds
     *
     * @return array<string, true>
     */
    private function auditedObjectIds(string $entity, array $objectIds): array
    {
        if ([] === $objectIds) {
            return [];
        }

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $auditTable = $configuration->getEntities()[$entity]['computed_audit_table_name'] ?? '';
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        /** @var list<int|string> $rows */
        $rows = $connection->fetchFirstColumn(
            \sprintf('SELECT DISTINCT object_id FROM %s WHERE object_id IN (?)', $auditTable),
            [$objectIds],
            [ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map(strval(...), $rows), true);
    }

    private function getDiscriminator(object $entity, int $inheritanceType): ?string
    {
        return ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $inheritanceType ? DoctrineHelper::getRealClassName($entity) : null;
    }
}
