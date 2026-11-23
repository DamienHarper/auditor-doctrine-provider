<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RootFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class RootFilterTest extends TestCase
{
    public function testSql(): void
    {
        $query = new Query('post_audit', $this->createStub(Connection::class), 'UTC')->setRootIndexTable('audit_root');
        $filter = new RootFilter('App\Author', 7, ['author']);

        $this->assertSame(Query::ROOT, $filter->getName());
        $this->assertSame('7', $filter->getRootId());

        $data = $filter->getSQLForQuery($query);
        $this->assertStringStartsWith('EXISTS (SELECT 1 FROM audit_root r WHERE r.root_class = :root_', $data['sql']);
        $this->assertStringContainsString('r.object_id = at.object_id AND r.transaction_id = at.transaction_id AND r.root_path IN (:root_', $data['sql']);
        $this->assertSame(['App\Author', '7', 'post_audit', ['author']], array_values($data['params']));

        $this->assertStringStartsWith('(SELECT MIN(r.root_path) FROM audit_root r', $filter->getRootPathSQL($query)['sql']);
    }

    public function testTheRootIndexTableMustBeSet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RootFilter('App\Author', 7)->getSQLForQuery(new Query('post_audit', $this->createStub(Connection::class), 'UTC'));
    }

    #[IgnoreDeprecations]
    public function testGetSqlNeedsTheQuery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RootFilter('App\Author', 7)->getSQL();
    }
}
