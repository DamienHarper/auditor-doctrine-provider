<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records the SQL of every statement sent to the database, to count the statements a piece of code runs.
 */
final class StatementLoggerMiddleware implements Middleware
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        $logger = $this;

        return new class($driver, $logger) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementLoggerMiddleware $logger)
            {
                parent::__construct($driver);
            }

            #[\Override]
            public function connect(array $params): Connection
            {
                $logger = $this->logger;

                return new class(parent::connect($params), $logger) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly StatementLoggerMiddleware $logger)
                    {
                        parent::__construct($connection);
                    }

                    #[\Override]
                    public function prepare(string $sql): Statement
                    {
                        $this->logger->log($sql);

                        return parent::prepare($sql);
                    }

                    #[\Override]
                    public function query(string $sql): Result
                    {
                        $this->logger->log($sql);

                        return parent::query($sql);
                    }

                    #[\Override]
                    public function exec(string $sql): int|string
                    {
                        $this->logger->log($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function log(string $sql): void
    {
        $this->statements[] = $sql;
    }

    /**
     * @return list<string>
     */
    public function statementsStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => str_starts_with(mb_ltrim($sql), $prefix),
        ));
    }

    public function clear(): void
    {
        $this->statements = [];
    }
}
