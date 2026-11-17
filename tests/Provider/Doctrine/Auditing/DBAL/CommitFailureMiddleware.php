<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Makes the next COMMIT fail with the given SQLSTATE, as the server does when it rolls the transaction back
 * at COMMIT (serialization failure, deferred constraint…) or when the connection is lost during the COMMIT.
 *
 * Registered below the auditor middleware, so that the audit flushers have run when the COMMIT fails.
 */
final class CommitFailureMiddleware implements Middleware
{
    private ?DriverException $nextFailure = null;

    public function failNextCommit(string $sqlState): void
    {
        $this->nextFailure = new class('COMMIT failed', $sqlState) extends AbstractException {};
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        $middleware = $this;

        return new class($driver, $middleware) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly CommitFailureMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            #[\Override]
            public function connect(array $params): Connection
            {
                $middleware = $this->middleware;

                return new class(parent::connect($params), $middleware) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly CommitFailureMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    #[\Override]
                    public function commit(): void
                    {
                        $failure = $this->middleware->takeFailure();
                        if ($failure instanceof DriverException) {
                            parent::rollBack();

                            throw $failure;
                        }

                        parent::commit();
                    }
                };
            }
        };
    }

    public function takeFailure(): ?DriverException
    {
        $failure = $this->nextFailure;
        $this->nextFailure = null;

        return $failure;
    }
}
