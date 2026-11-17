<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Event;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorDriver;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\AuditTrait;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
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
        // Populate transaction
        $this->provider->getTransactionManager()->populate($transaction);

        // A single flusher per database transaction processes everything populated until the commit.
        if ($driver instanceof AuditorDriver && !isset($this->flushers[$entityManagerId])) {
            $this->flushers[$entityManagerId] = function () use ($transaction): void {
                try {
                    $this->provider->getTransactionManager()->process($transaction);
                } finally {
                    $transaction->reset();
                }
            };
            $driver->addFlusher($this->flushers[$entityManagerId]);
        }
    }

    public function postSoftDelete(LifecycleEventArgs $args): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $args->getObjectManager();
        $transaction = $this->transaction($entityManager, $this->auditorDriver($entityManager));

        if ($this->provider->isAudited($args->getObject())) {
            $transaction->remove(
                $args->getObject(),
                $this->id($entityManager, $args->getObject()),
            );
        }
    }

    public function reset(): void
    {
        $this->transactions = [];
        $this->flushers = [];
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
     * Once the entity manager's flusher has left the driver's list, it either ran or was dropped because
     * its database transaction was rolled back or failed to commit: in both cases, the entries left in the
     * transaction model must not be written with the next commit.
     */
    private function transaction(EntityManagerInterface $entityManager, ?AuditorDriver $driver): Transaction
    {
        $entityManagerId = spl_object_id($entityManager);
        $transaction = ($this->transactions[$entityManagerId] ??= new Transaction($entityManager));

        $flusher = $this->flushers[$entityManagerId] ?? null;
        if ($flusher instanceof \Closure && !\in_array($flusher, $driver?->getFlusherList() ?? [], true)) {
            unset($this->flushers[$entityManagerId]);
            $transaction->reset();
        }

        return $transaction;
    }
}
