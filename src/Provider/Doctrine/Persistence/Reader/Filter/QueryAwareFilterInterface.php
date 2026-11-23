<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

/**
 * Interface for filters whose SQL depends on the query they are added to (its audit table, its connection or the
 * audit root index table).
 */
interface QueryAwareFilterInterface extends FilterInterface
{
    /**
     * Returns SQL and parameters for this filter in $query, whose audit table has the "at" alias.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function getSQLForQuery(Query $query): array;
}
