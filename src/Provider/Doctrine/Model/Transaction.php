<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Model;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Model\Transaction as BaseTransaction;
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
     * @param ?string $transactionId identifier shared with another transaction model written in the same database
     *                               transaction, generated when null
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $transactionId = null,
    ) {
        $this->removedIds = new \WeakMap();
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
}
