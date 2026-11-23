<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter\RootFilterTest;

/**
 * Keeps the audit entries indexed under an audit root (see #[AuditRoot]), optionally through some paths only.
 *
 * Roots are indexed under the root class of their inheritance hierarchy: Reader::createRootQuery() normalizes the
 * class, a filter built by hand (in a read scope for instance) must be given that class.
 *
 * @see RootFilterTest
 */
final readonly class RootFilter implements QueryAwareFilterInterface
{
    /**
     * @param list<string> $paths root paths to keep, all when empty
     */
    public function __construct(
        private string $rootClass,
        private int|string $rootId,
        private array $paths = [],
    ) {}

    public function getName(): string
    {
        return Query::ROOT;
    }

    public function getRootClass(): string
    {
        return $this->rootClass;
    }

    public function getRootId(): string
    {
        return (string) $this->rootId;
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    #[\Deprecated(message: 'Use getSQLForQuery() instead')]
    public function getSQL(): array
    {
        throw new InvalidArgumentException('RootFilter needs the query it is added to. Use getSQLForQuery() instead.');
    }

    public function getSQLForQuery(Query $query): array
    {
        return $this->subquery($query, 'EXISTS (SELECT 1', 'root_'.$this->hash(), ')');
    }

    /**
     * Returns the SQL expression of the path an entry was indexed under (the first one in alphabetical order when
     * it is indexed under the root through several paths).
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function getRootPathSQL(Query $query): array
    {
        return $this->subquery($query, '(SELECT MIN(r.root_path)', 'root_path_'.$this->hash(), ')');
    }

    private function hash(): string
    {
        // Suffix the parameter names so that several root filters can share a query.
        return hash('xxh32', $this->rootClass."\0".$this->rootId."\0".implode(',', $this->paths));
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function subquery(Query $query, string $select, string $param, string $end): array
    {
        $sql = \sprintf(
            '%1$s FROM %2$s r WHERE r.root_class = :%3$s_class AND r.root_id = :%3$s_id AND r.audit_table = :%3$s_table AND r.object_id = at.object_id AND r.transaction_id = at.transaction_id%4$s%5$s',
            $select,
            $query->getRootIndexTable(),
            $param,
            [] === $this->paths ? '' : \sprintf(' AND r.root_path IN (:%s_paths)', $param),
            $end,
        );

        $params = [
            $param.'_class' => $this->rootClass,
            $param.'_id' => (string) $this->rootId,
            $param.'_table' => $query->getTable(),
        ];

        if ([] !== $this->paths) {
            $params[$param.'_paths'] = $this->paths;
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
