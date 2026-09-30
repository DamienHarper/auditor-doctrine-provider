<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * @interal
 */
final class AuditorConnection extends AbstractConnectionMiddleware
{
    public function __construct(ConnectionInterface $connection, private readonly AuditorDriver $auditorDriver)
    {
        parent::__construct($connection);
    }

    #[\Override]
    public function commit(): void
    {
        $flusherList = $this->auditorDriver->getFlusherList();

        try {
            foreach ($flusherList as $flusher) {
                ($flusher)();
            }
        } catch (\Throwable $throwable) {
            // DBAL counts the transaction as finished even though commit() threw, so nobody calls
            // rollBack() afterwards: the flusher would stay in the list and fail every later commit
            // on this connection, and the server-side transaction would stay open.
            $this->auditorDriver->resetFlusherList();
            parent::rollBack();

            throw $throwable;
        }

        $this->auditorDriver->resetFlusherList();

        parent::commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->auditorDriver->resetFlusherList();

        parent::rollBack();
    }
}
