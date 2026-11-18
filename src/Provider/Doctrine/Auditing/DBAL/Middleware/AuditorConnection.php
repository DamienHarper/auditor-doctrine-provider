<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * @interal
 */
final class AuditorConnection extends AbstractConnectionMiddleware
{
    /**
     * SQLSTATE values of a COMMIT failure after which the server has rolled the transaction back (serialization
     * failure, transaction integrity constraint violation, deadlock), besides class 23 (integrity constraint
     * violation, e.g. a deferred constraint checked at COMMIT).
     */
    private const array ROLLED_BACK_SQLSTATES = ['40001', '40002', '40P01'];

    /**
     * Savepoint statements of the DBAL platforms: SAVEPOINT, RELEASE SAVEPOINT and ROLLBACK TO SAVEPOINT, or
     * SAVE TRANSACTION and ROLLBACK TRANSACTION (SQL Server).
     */
    private const string SAVEPOINT_PATTERN = '/^\s*(SAVEPOINT|SAVE\s+TRANSACTION|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT|ROLLBACK\s+TRANSACTION)\s+(\S+?)\s*;?\s*$/i';

    public function __construct(ConnectionInterface $connection, private readonly AuditorDriver $auditorDriver)
    {
        parent::__construct($connection);
    }

    /**
     * DBAL runs the savepoint statements of the nested transactions through exec(): they are tracked so that the
     * audit entries of a rolled-back nested transaction are not written.
     */
    #[\Override]
    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);

        $firstLetter = mb_strtoupper(mb_ltrim($sql)[0] ?? '');
        if (('S' === $firstLetter || 'R' === $firstLetter) && 1 === preg_match(self::SAVEPOINT_PATTERN, $sql, $matches)) {
            match (mb_strtoupper((string) preg_replace('/\s+/', ' ', $matches[1]))) {
                'SAVEPOINT', 'SAVE TRANSACTION' => $this->auditorDriver->createSavepoint($matches[2]),
                'RELEASE SAVEPOINT' => $this->auditorDriver->releaseSavepoint($matches[2]),
                default => $this->auditorDriver->rollbackSavepoint($matches[2]),
            };
        }

        return $result;
    }

    #[\Override]
    public function commit(): void
    {
        try {
            // A flusher may register another one, e.g. when a listener of the audit events flushes another entity
            // manager sharing this connection: it runs in the same pass, before the COMMIT.
            while ([] !== ($flusherList = $this->auditorDriver->getFlusherList())) {
                $this->auditorDriver->resetFlusherList();
                foreach ($flusherList as $flusher) {
                    ($flusher)();
                }
            }
        } catch (\Throwable $throwable) {
            // DBAL counts the transaction as finished even though commit() threw, so nobody calls
            // rollBack() afterwards: the flusher would stay in the list and fail every later commit
            // on this connection, and the server-side transaction would stay open.
            $this->auditorDriver->resetFlusherList();

            try {
                parent::rollBack();
            } catch (\Throwable) {
                // the audit failure is the root cause: a failing rollback (e.g. lost connection) must not hide it
            }

            // No COMMIT was sent: the transaction is not committed, whether the rollback succeeded or not.
            $this->auditorDriver->resetSavepoints();
            $this->runRollbackHandlers();

            throw $throwable;
        }

        try {
            parent::commit();
        } catch (DriverException $driverException) {
            // Audit entries written on another connection are discarded only when the server reports that
            // the transaction was rolled back. When the outcome is unknown (e.g. connection lost during the
            // COMMIT), the changes may be committed: an orphan entry is better than a missing one.
            if ($this->isRolledBack($driverException)) {
                $this->runRollbackHandlers();
            }

            throw $driverException;
        } finally {
            $this->auditorDriver->resetSavepoints();
            $this->auditorDriver->resetRollbackHandlerList();
        }
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->auditorDriver->resetFlusherList();
        $this->auditorDriver->resetRollbackHandlerList();
        $this->auditorDriver->resetSavepoints();

        parent::rollBack();
    }

    private function isRolledBack(DriverException $exception): bool
    {
        $sqlState = $exception->getSQLState();

        return null !== $sqlState
            && (\in_array($sqlState, self::ROLLED_BACK_SQLSTATES, true) || str_starts_with($sqlState, '23'));
    }

    private function runRollbackHandlers(): void
    {
        $handlerList = $this->auditorDriver->getRollbackHandlerList();
        $this->auditorDriver->resetRollbackHandlerList();

        foreach ($handlerList as $handler) {
            try {
                $handler();
            } catch (\Throwable) {
                // best effort: the commit failure must not be hidden, the remaining handlers still run
            }
        }
    }
}
