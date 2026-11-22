<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Event;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorDriver;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\Savepoint;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\AuditTrait;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Contracts\Service\ResetInterface;

final class DoctrineSubscriber implements ResetInterface
{
    use AuditTrait;

    /** @var Transaction[] */
    private array $transactions = [];

    /**
     * Flusher registered for each entity manager's transaction model, until it runs or is dropped.
     *
     * @var array<int, \Closure>
     */
    private array $flushers = [];

    /**
     * Entries of a flush run inside an outer database transaction, kept apart until postFlush confirms the flush,
     * with the unit of work they come from.
     *
     * @var array<int, array{Transaction, UnitOfWork}>
     */
    private array $pendingTransactions = [];

    /**
     * Entries of the flushes run inside an outer database transaction and confirmed by postFlush, with the savepoint
     * current when they were confirmed: they are not written if the database transaction is rolled back to that
     * savepoint, or to one of its parents, before it is committed.
     *
     * @var array<int, list<array{Transaction, ?Savepoint}>>
     */
    private array $confirmedTransactions = [];

    /**
     * Entity managers whose flusher is running.
     *
     * @var array<int, true>
     */
    private array $runningFlushers = [];

    /**
     * Audit tables stored on another connection than each entity manager's one, grouped by connection.
     *
     * @var array<int, list<array{Connection, list<string>}>>
     */
    private array $remoteAuditTables = [];

    public function __construct(
        private readonly DoctrineProvider $provider // kept for BC, no longer used directly
    ) {}

    /**
     * It is called inside EntityManager#flush() after the changes to all the managed entities
     * and their associations have been computed.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#onflush
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $args->getObjectManager();
        $entityManagerId = spl_object_id($entityManager);
        $driver = $this->auditorDriver($entityManager);
        $transaction = $this->transaction($entityManager, $driver);
        // Populate transaction, with the blame context current during this flush: the audits are written at commit
        // time, when the context may already have been popped
        $flushTransaction = $this->flushTransaction($entityManager, $transaction);
        $flushTransaction->setBlameContext($this->provider->getAuditor()->getConfiguration()->getBlameContextStack()->current());
        $this->provider->getTransactionManager()->populate($flushTransaction);

        // A single flusher per database transaction processes everything populated until the commit.
        if ($driver instanceof AuditorDriver && !isset($this->flushers[$entityManagerId])) {
            $this->flushers[$entityManagerId] = function () use ($transaction, $entityManager, $entityManagerId, $driver): void {
                $this->runningFlushers[$entityManagerId] = true;
                $hasEntries = false;
                // with a positive `batch_size`, audit entries are written by the driver when the transaction is committed
                $previousBuffer = $this->provider->useInsertBuffer($driver->getInsertBuffer());

                try {
                    // a listener of the audit events may flush again: its entries are written in the same pass
                    for ($batch = $transaction; $batch instanceof Transaction; $batch = $this->nextConfirmedTransaction($entityManagerId)) {
                        $hasEntries = $hasEntries || !$this->isEmpty($batch);
                        $this->provider->getTransactionManager()->process($batch);
                    }
                } finally {
                    // a listener may have committed another transaction while this one is processed
                    $this->provider->useInsertBuffer($previousBuffer);
                    unset($this->runningFlushers[$entityManagerId], $this->confirmedTransactions[$entityManagerId], $this->flushers[$entityManagerId]);
                    if ($hasEntries) {
                        $this->addRollbackHandler($driver, $entityManager, $transaction->getTransactionId());
                    }

                    $transaction->reset();
                }
            };
            $driver->addFlusher($this->flushers[$entityManagerId]);
        }
    }

    /**
     * It is called at the end of a successful EntityManager#flush(): the entries of a flush run inside an outer
     * database transaction join the ones that will be written when that transaction is committed (or by the running
     * flusher of the entity manager), unless the transaction is rolled back to a savepoint created before the flush.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#postflush
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $entityManagerId = spl_object_id($entityManager);
        if (!isset($this->pendingTransactions[$entityManagerId])) {
            return;
        }

        [$pending] = $this->pendingTransactions[$entityManagerId];
        unset($this->pendingTransactions[$entityManagerId]);

        $driver = $this->auditorDriver($entityManager);
        $transaction = new Transaction($entityManager, $this->transaction($entityManager, $driver)->getTransactionId());
        $transaction->setBlameContext($pending->getBlameContext());
        $this->confirmedTransactions[$entityManagerId][] = [$transaction, $driver?->getCurrentSavepoint()];

        foreach ($pending->getInserted() as $dto) {
            $transaction->insert($dto->source, $dto->getChangeset());
        }

        foreach ($pending->getUpdated() as $dto) {
            $transaction->update($dto->source, $dto->getChangeset());
        }

        foreach ($pending->getRemoved() as $dto) {
            $transaction->remove($dto->source, $dto->getId());
        }

        foreach ($pending->getAssociated() as $dto) {
            $transaction->associate($dto->source, $dto->getTarget(), $dto->getMapping());
        }

        foreach ($pending->getDissociated() as $dto) {
            $target = $dto->getTarget();
            $transaction->dissociate($dto->source, $target, $dto->getMapping());

            // Identifier of a target deleted by this flush, captured before Doctrine reset it.
            $removedId = $pending->getRemovedId($target);
            if (null !== $removedId) {
                $transaction->rememberRemovedId($target, $removedId);
            }
        }
    }

    public function postSoftDelete(LifecycleEventArgs $args): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $args->getObjectManager();
        $transaction = $this->transaction($entityManager, $this->auditorDriver($entityManager));

        if ($this->provider->isAudited($args->getObject())) {
            $this->flushTransaction($entityManager, $transaction)->remove(
                $args->getObject(),
                $this->id($entityManager, $args->getObject()),
            );
        }
    }

    public function reset(): void
    {
        $this->transactions = [];
        $this->flushers = [];
        $this->pendingTransactions = [];
        $this->confirmedTransactions = [];
        $this->runningFlushers = [];
        $this->remoteAuditTables = [];
    }

    /**
     * @internal this method is used to retrieve the wrapped driver from the given driver
     */
    public function getWrappedDriver(Driver $driver): \Closure|Driver
    {
        $that = $this;

        // if the driver is already a DHDriver, return it
        if ($driver instanceof AuditorDriver) {
            return $driver;
        }

        // if the driver is an instance of AbstractDriverMiddleware, return the wrapped driver
        if ($driver instanceof AbstractDriverMiddleware) {
            return \Closure::bind(fn (): \Closure|\Doctrine\DBAL\Driver
                // @var AbstractDriverMiddleware $this
                => $that->getWrappedDriver($this->wrappedDriver), $driver, AbstractDriverMiddleware::class)();
        }

        return \Closure::bind(function () use ($that): \Closure|Driver|null {
            /** @var Driver $this */
            $properties = new \ReflectionClass($this)->getProperties();
            foreach ($properties as $property) {
                $value = $property->getValue($this);
                if ($value instanceof Driver) {
                    return $that->getWrappedDriver($value);
                }
            }

            return null;
        }, $driver, Driver::class)() ?: $driver;
    }

    private function auditorDriver(EntityManagerInterface $entityManager): ?AuditorDriver
    {
        $driver = $this->getWrappedDriver($entityManager->getConnection()->getDriver());

        return $driver instanceof AuditorDriver ? $driver : null;
    }

    /**
     * Returns the cached transaction model of the entity manager (one per entity manager).
     *
     * A flusher that ran forgets itself. One that left the driver's list without running was dropped because its
     * database transaction was rolled back or failed to commit: the entries left in the transaction model must not
     * be written with the next commit. A running flusher has left the list too.
     */
    private function transaction(EntityManagerInterface $entityManager, ?AuditorDriver $driver): Transaction
    {
        $entityManagerId = spl_object_id($entityManager);
        $transaction = ($this->transactions[$entityManagerId] ??= new Transaction($entityManager));

        $flusher = $this->flushers[$entityManagerId] ?? null;
        if ($flusher instanceof \Closure
            && !isset($this->runningFlushers[$entityManagerId])
            && !\in_array($flusher, $driver?->getFlusherList() ?? [], true)
        ) {
            unset($this->flushers[$entityManagerId], $this->confirmedTransactions[$entityManagerId]);
            $transaction->reset();
        }

        return $transaction;
    }

    /**
     * Returns the transaction model the current flush populates.
     *
     * A flush that opens its own database transaction populates the entity manager's transaction model: if the
     * flush fails, that whole transaction is rolled back and its flusher dropped. Inside an outer transaction,
     * a failed flush only rolls back to its savepoint: its entries are kept apart until postFlush confirms it.
     * Entries left by a flush that never reached postFlush are discarded once the outer transaction is over, or
     * once the entity manager has been reset (a failed flush closes it).
     */
    private function flushTransaction(EntityManagerInterface $entityManager, Transaction $transaction): Transaction
    {
        $entityManagerId = spl_object_id($entityManager);
        $unitOfWork = $entityManager->getUnitOfWork();

        if (0 === $entityManager->getConnection()->getTransactionNestingLevel()) {
            unset($this->pendingTransactions[$entityManagerId]);

            return $transaction;
        }

        if (($this->pendingTransactions[$entityManagerId][1] ?? null) !== $unitOfWork) {
            $this->pendingTransactions[$entityManagerId] = [new Transaction($entityManager), $unitOfWork];
        }

        return $this->pendingTransactions[$entityManagerId][0];
    }

    /**
     * Audit entries stored on another connection are written outside of the audited database transaction: they
     * are deleted if that transaction turns out to be rolled back after they were written.
     */
    private function addRollbackHandler(AuditorDriver $driver, EntityManagerInterface $entityManager, string $transactionId): void
    {
        $remoteAuditTables = $this->remoteAuditTables($entityManager);
        if ([] === $remoteAuditTables) {
            return;
        }

        $logger = $this->provider->getLogger();
        $driver->addRollbackHandler(static function () use ($remoteAuditTables, $transactionId, $logger): void {
            foreach ($remoteAuditTables as [$connection, $auditTables]) {
                foreach ($auditTables as $auditTable) {
                    try {
                        $connection->executeStatement(\sprintf('DELETE FROM %s WHERE transaction_id = ?', $auditTable), [$transactionId]);
                    } catch (\Throwable $throwable) {
                        // The rollback is being reported: the failure is logged, not thrown, and the other audit
                        // tables are still cleaned up. The transaction id identifies the entries left behind.
                        $logger->error('The audit entries of the rolled-back transaction {transaction_id} could not be deleted from {table}.', [
                            'transaction_id' => $transactionId,
                            'table' => $auditTable,
                            'exception' => $throwable,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Returns the next entries confirmed by postFlush whose changes were not rolled back to a savepoint.
     */
    private function nextConfirmedTransaction(int $entityManagerId): ?Transaction
    {
        while ([] !== ($this->confirmedTransactions[$entityManagerId] ?? [])) {
            $confirmed = array_shift($this->confirmedTransactions[$entityManagerId]);
            [$transaction, $savepoint] = $confirmed;
            if (!$savepoint instanceof Savepoint || !$savepoint->isRolledBack()) {
                return $transaction;
            }
        }

        return null;
    }

    private function isEmpty(Transaction $transaction): bool
    {
        return [] === $transaction->getInserted()
            && [] === $transaction->getUpdated()
            && [] === $transaction->getRemoved()
            && [] === $transaction->getAssociated()
            && [] === $transaction->getDissociated();
    }

    /**
     * Audit tables of the audited entities stored on another connection than the entity manager's one. Deleting
     * by transaction id in a table this transaction did not write to is harmless.
     *
     * @return list<array{Connection, list<string>}>
     */
    private function remoteAuditTables(EntityManagerInterface $entityManager): array
    {
        $entityManagerId = spl_object_id($entityManager);
        if (isset($this->remoteAuditTables[$entityManagerId])) {
            return $this->remoteAuditTables[$entityManagerId];
        }

        $connection = $entityManager->getConnection();
        $configuration = $this->provider->getConfiguration();
        \assert($configuration instanceof Configuration);   // helps PHPStan

        $remoteAuditTables = [];
        foreach ($configuration->getEntities() as $entity => $entityConfiguration) {
            $auditTable = $entityConfiguration['computed_audit_table_name'] ?? null;
            if (!\is_string($auditTable) || '' === $auditTable) {
                continue;
            }

            try {
                $storageConnection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
            } catch (\Exception) {
                continue;
            }

            if ($storageConnection !== $connection) {
                $remoteAuditTables[spl_object_id($storageConnection)] ??= [$storageConnection, []];
                $remoteAuditTables[spl_object_id($storageConnection)][1][] = $auditTable;
            }
        }

        return $this->remoteAuditTables[$entityManagerId] = array_values($remoteAuditTables);
    }
}
