<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ImpersonatorFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Reading through the filter is covered by BlameUserTest.
 *
 * @internal
 */
#[Small]
final class ImpersonatorFilterTest extends TestCase
{
    public function testAnyImpersonator(): void
    {
        $filter = new ImpersonatorFilter();

        $this->assertSame(Query::BLAME, $filter->getName());
        $this->assertNull($filter->getImpersonatorId());
    }

    public function testImpersonatorId(): void
    {
        $this->assertSame('1', new ImpersonatorFilter('1')->getImpersonatorId());
    }

    public function testEmptyImpersonatorId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImpersonatorFilter('');
    }

    public function testGetSqlNeedsAConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImpersonatorFilter()->getSQL();
    }
}
