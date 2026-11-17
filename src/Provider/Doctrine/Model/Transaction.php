<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Model;

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

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        $this->removedIds = new \WeakMap();
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function reset(): void
    {
        parent::reset();
        $this->removedIds = new \WeakMap();
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
