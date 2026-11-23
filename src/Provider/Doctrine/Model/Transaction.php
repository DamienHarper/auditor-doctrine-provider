<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Model;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Model\Transaction as BaseTransaction;
use DH\Auditor\Provider\Doctrine\Auditing\Root\RootReference;
use Doctrine\ORM\EntityManagerInterface;

final class Transaction extends BaseTransaction
{
    /**
     * Identifiers of the entities deleted in the current flush, captured before Doctrine
     * executes the DELETE statements and resets their generated identifier.
     *
     * @var \WeakMap<object, mixed>
     */
    private \WeakMap $removedIds;

    /**
     * Blame context current when the entries were collected: the audits are written at commit time, when the
     * context may already have been popped.
     */
    private ?BlameContext $blameContext = null;

    /**
     * Audit roots of the entities of the transaction, collected when each flush starts.
     *
     * @var \WeakMap<object, array<string, RootReference>>
     */
    private \WeakMap $roots;

    /**
     * @param ?string $transactionId identifier shared with another transaction model written in the same database
     *                               transaction, generated when null
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $transactionId = null,
    ) {
        $this->removedIds = new \WeakMap();
        $this->roots = new \WeakMap();
    }

    #[\Override]
    public function getTransactionId(): string
    {
        return $this->transactionId ?? parent::getTransactionId();
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function reset(): void
    {
        parent::reset();
        $this->removedIds = new \WeakMap();
        $this->roots = new \WeakMap();
        $this->blameContext = null;
    }

    public function setBlameContext(?BlameContext $blameContext): void
    {
        $this->blameContext = $blameContext;
    }

    public function getBlameContext(): ?BlameContext
    {
        return $this->blameContext;
    }

    public function rememberRemovedId(object $entity, mixed $id): void
    {
        $this->removedIds[$entity] = $id;
    }

    /**
     * Returns the identifier captured for an entity deleted in the current flush, or null.
     */
    public function getRemovedId(object $entity): mixed
    {
        return $this->removedIds[$entity] ?? null;
    }

    /**
     * Adds audit roots of an entity: the roots found by several flushes of the transaction add up.
     *
     * @param list<RootReference> $roots
     */
    public function addRoots(object $entity, array $roots): void
    {
        $current = $this->roots[$entity] ?? [];
        foreach ($roots as $root) {
            $current[$root->key()] = $root;
        }

        $this->roots[$entity] = $current;
    }

    public function hasRoots(object $entity): bool
    {
        return isset($this->roots[$entity]);
    }

    /**
     * @return list<RootReference>
     */
    public function getRoots(object $entity): array
    {
        return array_values($this->roots[$entity] ?? []);
    }

    /**
     * @return \WeakMap<object, array<string, RootReference>>
     */
    public function getAllRoots(): \WeakMap
    {
        return $this->roots;
    }
}
