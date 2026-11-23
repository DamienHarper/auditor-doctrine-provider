<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\CursorFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class CursorFilterTest extends TestCase
{
    public function testBefore(): void
    {
        $filter = new CursorFilter('2026-11-23 10:00:00', '01TX', CursorFilter::TIE_ID, 42);

        $this->assertSame(Query::CURSOR, $filter->getName());
        $this->assertSame([
            'sql' => "(at.created_at < :cursor_before_date OR (at.created_at = :cursor_before_date AND (COALESCE(at.transaction_id, '') < :cursor_before_tx OR (COALESCE(at.transaction_id, '') = :cursor_before_tx AND at.id < :cursor_before_id))))",
            'params' => ['cursor_before_date' => '2026-11-23 10:00:00', 'cursor_before_tx' => '01TX', 'cursor_before_id' => 42],
        ], $filter->getSQL());
    }

    public function testAfter(): void
    {
        $all = new CursorFilter('2026-11-23 10:00:00', '01TX', CursorFilter::TIE_ALL, before: false)->getSQL();
        $none = new CursorFilter('2026-11-23 10:00:00', '01TX', CursorFilter::TIE_NONE, before: false)->getSQL();

        $this->assertStringContainsString('at.created_at > :cursor_after_date', $all['sql']);
        $this->assertStringContainsString('= :cursor_after_tx AND 1 = 1)', $all['sql']);
        $this->assertStringContainsString('= :cursor_after_tx AND 1 = 0)', $none['sql']);
        $this->assertArrayNotHasKey('cursor_after_id', $all['params']);
    }

    public function testInvalidTieMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CursorFilter('2026-11-23 10:00:00', '01TX', 'some');
    }
}
