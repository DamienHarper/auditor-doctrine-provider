<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

/**
 * A savepoint of the database transaction in progress, e.g. the one DBAL creates for a nested transaction.
 *
 * Rolling back to a savepoint undoes the changes made since it was created, including the ones of the savepoints
 * created after it: the changes made while a savepoint was the current one are undone once it, or one of its
 * parents, has been rolled back to.
 *
 * @internal
 */
final class Savepoint
{
    private bool $rolledBack = false;

    public function __construct(
        public readonly string $name,
        public readonly ?self $parent = null,
    ) {}

    public function rollBack(): void
    {
        $this->rolledBack = true;
    }

    public function isRolledBack(): bool
    {
        for ($savepoint = $this; $savepoint instanceof self; $savepoint = $savepoint->parent) {
            if ($savepoint->rolledBack) {
                return true;
            }
        }

        return false;
    }
}
