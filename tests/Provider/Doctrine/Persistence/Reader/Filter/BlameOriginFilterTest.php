<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\BlameOriginFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Reading through the filter is covered by RootQueryTest.
 *
 * @internal
 */
#[Small]
final class BlameOriginFilterTest extends TestCase
{
    public function testOrigins(): void
    {
        $filter = new BlameOriginFilter(['console', BlameOrigin::Console, BlameOrigin::Http]);

        $this->assertSame(Query::BLAME, $filter->getName());
        $this->assertSame([BlameOrigin::Console, BlameOrigin::Http], $filter->getOrigins());
    }

    public function testInvalidOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid blame origin "cli"');

        new BlameOriginFilter('cli');
    }

    public function testNoOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlameOriginFilter([]);
    }
}
