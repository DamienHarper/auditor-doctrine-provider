<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @interal
 */
final class AuditorDriver extends AbstractDriverMiddleware
{
    /** @var array<callable> */
    private array $flusherList = [];

    /**
     * Callbacks registered by the flushers of the commit in progress, run once the database transaction
     * is known to be rolled back: they discard the audit entries already written on another connection.
     *
     * @var array<callable>
     */
    private array $rollbackHandlerList = [];

    /**
     * Innermost savepoint of the database transaction in progress, tracked from the statements run by the connection.
     */
    private ?Savepoint $currentSavepoint = null;

    #[\Override]
    public function connect(array $params): AuditorConnection
    {
        return new AuditorConnection(parent::connect($params), $this);
    }

    public function addFlusher(callable $flusher): void
    {
        $this->flusherList[] = $flusher;
    }

    public function resetFlusherList(): void
    {
        $this->flusherList = [];
    }

    public function getFlusherList(): array
    {
        return $this->flusherList;
    }

    public function addRollbackHandler(callable $handler): void
    {
        $this->rollbackHandlerList[] = $handler;
    }

    public function resetRollbackHandlerList(): void
    {
        $this->rollbackHandlerList = [];
    }

    /**
     * @return array<callable>
     */
    public function getRollbackHandlerList(): array
    {
        return $this->rollbackHandlerList;
    }

    public function getCurrentSavepoint(): ?Savepoint
    {
        return $this->currentSavepoint;
    }

    public function createSavepoint(string $name): void
    {
        $this->currentSavepoint = new Savepoint($name, $this->currentSavepoint);
    }

    /**
     * Releasing a savepoint also releases the ones created after it: their changes now belong to its parent.
     */
    public function releaseSavepoint(string $name): void
    {
        $savepoint = $this->findSavepoint($name);
        if ($savepoint instanceof Savepoint) {
            $this->currentSavepoint = $savepoint->parent;
        }
    }

    /**
     * The savepoint rolled back to stays established: the changes made after the rollback start a new one.
     */
    public function rollbackSavepoint(string $name): void
    {
        $savepoint = $this->findSavepoint($name);
        if ($savepoint instanceof Savepoint) {
            $savepoint->rollBack();
            $this->currentSavepoint = new Savepoint($savepoint->name, $savepoint->parent);
        }
    }

    public function resetSavepoints(): void
    {
        $this->currentSavepoint = null;
    }

    private function findSavepoint(string $name): ?Savepoint
    {
        for ($savepoint = $this->currentSavepoint; $savepoint instanceof Savepoint; $savepoint = $savepoint->parent) {
            if ($savepoint->name === $name) {
                return $savepoint;
            }
        }

        return null;
    }
}
