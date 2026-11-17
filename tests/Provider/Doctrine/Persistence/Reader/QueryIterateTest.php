<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class QueryIterateTest extends TestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    public function testIterateReturnsGenerator(): void
    {
        $this->assertInstanceOf(\Generator::class, $this->createAuthorQuery()->iterate());
    }

    public function testIterateYieldsTheSameRowsAsExecute(): void
    {
        $entries = $this->createAuthorQuery()->execute();
        $rows = iterator_to_array($this->createAuthorQuery()->iterate(), false);

        $this->assertNotEmpty($entries, 'The blog fixtures must have produced author audit entries.');
        $this->assertCount(\count($entries), $rows);
        $this->assertSame(
            array_map(static fn (Entry $entry): string => $entry->objectId, $entries),
            array_map(static fn (array $row): string => (string) $row['object_id'], $rows),
        );
    }

    public function testIterateYieldsAssociativeArrays(): void
    {
        $rows = iterator_to_array($this->createAuthorQuery()->iterate(), false);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('type', $row);
            $this->assertArrayHasKey('object_id', $row);
            $this->assertArrayHasKey('created_at', $row);
        }
    }

    public function testIterateYieldsRawStringForCreatedAt(): void
    {
        $rows = iterator_to_array($this->createAuthorQuery()->iterate(), false);

        $this->assertNotEmpty($rows);
        $this->assertIsString($rows[0]['created_at'], 'iterate() must yield raw string for created_at, not DateTimeImmutable');
    }

    public function testIterateRespectsFilters(): void
    {
        $objectId = $this->createAuthorQuery()->execute()[0]->objectId;

        $query = $this->createAuthorQuery();
        $query->addFilter(new SimpleFilter(Query::OBJECT_ID, $objectId));

        $rows = iterator_to_array($query->iterate(), false);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($objectId, (string) $row['object_id']);
        }
    }

    public function testIterateYieldsNothingOnEmptyResult(): void
    {
        $query = $this->createAuthorQuery();
        $query->addFilter(new SimpleFilter(Query::OBJECT_ID, 'nonexistent'));

        $this->assertCount(0, iterator_to_array($query->iterate(), false));
    }

    private function createAuthorQuery(): Query
    {
        return $this->createReader()->createQuery(Author::class, ['page_size' => null]);
    }
}
