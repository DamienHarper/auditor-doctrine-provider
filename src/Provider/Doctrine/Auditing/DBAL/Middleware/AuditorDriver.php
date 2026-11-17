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
}
