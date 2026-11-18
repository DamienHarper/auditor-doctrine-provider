<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

use Doctrine\DBAL\Driver;
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
     * Audit entries of the commit in progress written with multi-row INSERT statements (`batch_size` option).
     */
    private readonly AuditInsertBuffer $insertBuffer;

    public function __construct(Driver $wrappedDriver)
    {
        parent::__construct($wrappedDriver);

        $this->insertBuffer = new AuditInsertBuffer();
    }

    #[\Override]
    public function connect(array $params): AuditorConnection
    {
        return new AuditorConnection(parent::connect($params), $this);
    }

    public function getInsertBuffer(): AuditInsertBuffer
    {
        return $this->insertBuffer;
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
