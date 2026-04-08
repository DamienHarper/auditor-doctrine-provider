<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Model\TransactionInterface;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Transaction\TransactionProcessorTest;
use DH\Auditor\Transaction\TransactionProcessorInterface;
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

    public function __construct(private DoctrineProvider $provider) {}

    /**
     * @param Transaction $transaction
     */
    public function process(TransactionInterface $transaction): void
    {
        $em = $transaction->getEntityManager();
        $blame = $this->blame();
        $this->preComputeFlushContext($blame);
        $this->processInsertions($transaction, $em);
        $this->processUpdates($transaction, $em);
        $this->processAssociations($transaction, $em);
        $this->processDissociations($transaction, $em);
        $this->processDeletions($transaction, $em);
    }

    /**
     * Pre-computes blame JSON and timestamp once for the entire flush.
     * Avoids repeated json_encode() and DateTimeImmutable creation per audit entry.
     *
     * @param array{client_ip: ?string, user_firewall: ?string, user_fqdn: ?string, user_id: ?string, username: ?string} $blame
     */
    private function preComputeFlushContext(array $blame): void
    {
        $tz = $this->dateTimeZone ??= new \DateTimeZone($this->provider->getAuditor()->getConfiguration()->timezone);
        $this->flushCreatedAt = new \DateTimeImmutable('now', $tz)->format('Y-m-d H:i:s.u');
        $this->flushBlameId = $blame['user_id'];
        $hasBlame = null !== $blame['user_id']
            || null !== $blame['username']
            || null !== $blame['user_fqdn']
            || null !== $blame['user_firewall']
            || null !== $blame['client_ip'];
        $this->flushBlameJson = $hasBlame
            ? json_encode([
                'username' => $blame['username'],
                'user_fqdn' => $blame['user_fqdn'],
                'user_firewall' => $blame['user_firewall'],
                'ip' => $blame['client_ip'],
            ], JSON_THROW_ON_ERROR)
            : null;
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
            $diff = $this->snapshot($entityManager, $entity, $meta);
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
     */
    private function dissociate(EntityManagerInterface $entityManager, object $source, object $target, array $mapping, string $transactionId): void
    {
        $this->associateOrDissociate(TransactionType::Dissociate, $entityManager, $source, $target, $mapping, $transactionId);
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
            $this->dissociate($entityManager, $dto->source, $dto->getTarget(), $dto->getMapping(), $transaction->getTransactionId());
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
    private function associateOrDissociate(TransactionType $type, EntityManagerInterface $entityManager, object $source, object $target, array $mapping, string $transactionId): void
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($source));
        $data = [
            'action' => $type,
            'diff' => [
                'source' => $this->summarize($entityManager, $source, ['field' => $mapping['fieldName']], $meta),
                'target' => $this->summarize($entityManager, $target, ['field' => $mapping['isOwningSide'] ? ($mapping['inversedBy'] ?? null) : ($mapping['mappedBy'] ?? null)]),
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

        // Populate entity config cache if not already done by diff() (e.g. remove/associate).
        // The cache correctly handles quoted identifiers for computed_audit_table_name.
        if (!isset($this->entityConfigCache[$entityClass])) {
            $entityCfg = $configuration->getEntities()[$entityClass] ?? [];
            $this->entityConfigCache[$entityClass] = [
                'computed_audit_table_name' => $entityCfg['computed_audit_table_name'] ?? '',
                'ignored_columns' => $entityCfg['ignored_columns'] ?? [],
                'diff_label_resolvers' => $entityCfg['diff_label_resolvers'] ?? [],
            ];
        }

        $auditTable = $this->entityConfigCache[$entityClass]['computed_audit_table_name'];
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
            'extra_data' => $this->extraData(),
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

    private function getDiscriminator(object $entity, int $inheritanceType): ?string
    {
        return ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $inheritanceType ? DoctrineHelper::getRealClassName($entity) : null;
    }
}
