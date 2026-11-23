<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Makes the first SQL statement matching a pattern fail, to interrupt the migration in the middle of a table
 * alteration. Inactive while $pattern is null.
 */
final class FailingStatementMiddleware implements Middleware
{
    public static ?string $pattern = null;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        FailingStatementMiddleware::check($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        FailingStatementMiddleware::check($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        FailingStatementMiddleware::check($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public static function check(string $sql): void
    {
        if (null !== self::$pattern && 1 === preg_match(self::$pattern, $sql)) {
            self::$pattern = null;

            throw new \RuntimeException('Statement made to fail: '.$sql);
        }
    }
}
