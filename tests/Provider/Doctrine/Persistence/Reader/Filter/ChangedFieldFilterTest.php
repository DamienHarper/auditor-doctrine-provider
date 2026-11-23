<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ChangedFieldFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class ChangedFieldFilterTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testGetName(): void
    {
        $filter = new ChangedFieldFilter('title');

        $this->assertSame(Query::DIFFS, $filter->getName());
        $this->assertSame('title', $filter->getField());
    }

    public function testSeveralFields(): void
    {
        $filter = new ChangedFieldFilter(['title', 'body', 'title']);

        $this->assertSame(['title', 'body'], $filter->getFields());
    }

    #[DataProvider('provideRejectsInvalidFieldNamesCases')]
    public function testRejectsInvalidFieldNames(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field name');

        new ChangedFieldFilter($field);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsInvalidFieldNamesCases(): iterable
    {
        yield 'empty' => [''];

        yield 'quote' => ["title' OR 1=1 --"];

        yield 'double quote' => ['title"'];

        yield 'json path' => ['$.title'];

        yield 'wildcard' => ['*'];

        yield 'leading digit' => ['1title'];

        yield 'trailing dot' => ['address.'];

        yield 'space' => ['created at'];
    }

    #[IgnoreDeprecations]
    public function testGetSqlRequiresAConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangedFieldFilter('title')->getSQL();
    }

    /**
     * The SQL predicate must select exactly the entries whose decoded diffs touch the field.
     */
    public function testMatchesTheEntriesWhoseDiffsTouchTheField(): void
    {
        $reader = new Reader($this->provider);

        foreach ([Post::class, Author::class] as $entity) {
            $entries = $reader->createQuery($entity, ['page_size' => null])->execute();
            $this->assertNotEmpty($entries);

            foreach (['title', 'body', 'author', 'coauthor', 'tags', 'comments', 'fullname', 'posts', 'source', 'target', 'id', 'class'] as $field) {
                $this->assertSame(
                    $this->expectedIds($entries, $field),
                    $this->ids($reader->createQuery($entity, ['changed_field' => $field, 'page_size' => null])->execute()),
                    \sprintf('Entries of %s touching "%s"', $entity, $field),
                );
            }
        }
    }

    public function testMatchesEveryOperationType(): void
    {
        $reader = new Reader($this->provider);

        $this->assertSame(['insert'], $this->types($reader->createQuery(Post::class, ['changed_field' => 'title', 'page_size' => null])->execute()));
        $this->assertSame(['update'], $this->types($reader->createQuery(Post::class, ['changed_field' => 'coauthor', 'page_size' => null])->execute()));
        $this->assertSame(['insert', 'update'], $this->types($reader->createQuery(Author::class, ['changed_field' => 'fullname', 'page_size' => null])->execute()));
        $this->assertSame(['associate', 'dissociate'], $this->types($reader->createQuery(Post::class, ['changed_field' => 'tags', 'page_size' => null])->execute()));
        $this->assertCount(0, $reader->createQuery(Post::class, ['changed_field' => 'source', 'page_size' => null])->execute(), 'Association envelope keys are not fields.');
    }

    public function testCombinesWithOtherFiltersAndPagination(): void
    {
        $reader = new Reader($this->provider);

        $all = $reader->createQuery(Post::class, ['changed_field' => 'tags', 'page_size' => null])->execute();
        $this->assertCount(10, $all);

        $query = $reader->createQuery(Post::class, ['changed_field' => 'tags', 'type' => 'dissociate', 'page' => 1, 'page_size' => 1]);
        $this->assertSame(2, $query->count());
        $this->assertCount(1, $query->execute());

        $query = $reader->createQuery(Post::class, ['changed_field' => 'tags', 'page' => 2, 'page_size' => 4]);
        $this->assertSame(10, $query->count());
        $this->assertSame(\array_slice($this->ids($all), 4, 4), $this->ids($query->execute()));
    }

    /**
     * Rows not converted by audit:schema:migrate --convert-diffs keep the legacy format
     * (schema_version = 1) and must be matched as well, without their identity keys being
     * mistaken for fields. Removes match only when recorded with a full snapshot, and dotted
     * (embeddable) field names must be matched whole.
     */
    public function testMatchesRowsOfEveryFormat(): void
    {
        $reader = new Reader($this->provider);
        $table = $reader->getEntityAuditTableName(Post::class);

        $insert = $this->insertRow($table, 1, 'insert', ['title' => ['new' => 'Legacy insert']]);
        $update = $this->insertRow($table, 1, 'update', [
            '@source' => ['id' => 1, 'class' => Post::class, 'label' => 'Legacy', 'table' => 'post'],
            'title' => ['old' => 'Legacy insert', 'new' => 'Legacy update'],
        ]);
        $remove = $this->insertRow($table, 1, 'remove', ['class' => Post::class, 'id' => 1, 'label' => 'Legacy', 'table' => 'post']);
        $associate = $this->insertRow($table, 1, 'associate', [
            'source' => ['id' => 1, 'class' => Post::class, 'label' => 'Legacy', 'table' => 'post', 'field' => 'tags'],
            'target' => ['id' => 1, 'class' => 'Tag', 'label' => 'techno', 'table' => 'tag', 'field' => 'posts'],
            'is_owning_side' => true,
            'table' => 'post__tag',
        ]);
        $dotted = $this->insertRow($table, 2, 'update', [
            'source' => ['id' => 1, 'class' => Post::class, 'label' => 'Embedded', 'table' => 'post'],
            'changes' => ['address.street' => ['old' => 'Old street', 'new' => 'New street']],
        ]);

        // A legacy entity field may be named like a key of the v2 envelope.
        $legacyChangesField = $this->insertRow($table, 1, 'update', ['changes' => ['old' => 'a', 'new' => 'b']]);

        $source = ['id' => 1, 'class' => Post::class, 'label' => 'Removed', 'table' => 'post'];
        $snapshotRemove = $this->insertRow($table, 2, 'remove', ['source' => $source, 'changes' => ['title' => ['old' => 'Removed', 'new' => null]]]);
        $bareRemove = $this->insertRow($table, 2, 'remove', ['source' => $source, 'changes' => []]);

        $titleIds = $this->ids($reader->createQuery(Post::class, ['changed_field' => 'title', 'page_size' => null])->execute());
        $this->assertContains($insert, $titleIds);
        $this->assertContains($update, $titleIds);
        $this->assertContains($snapshotRemove, $titleIds);
        $this->assertNotContains($bareRemove, $titleIds);

        $tagsIds = $this->ids($reader->createQuery(Post::class, ['changed_field' => 'tags', 'page_size' => null])->execute());
        $this->assertContains($associate, $tagsIds);

        foreach (['id', 'class', 'label', 'table'] as $field) {
            $this->assertNotContains($remove, $this->ids($reader->createQuery(Post::class, ['changed_field' => $field, 'page_size' => null])->execute()), $field);
        }

        $this->assertSame([$legacyChangesField], $this->ids($reader->createQuery(Post::class, ['changed_field' => 'changes', 'page_size' => null])->execute()));
        $this->assertCount(0, $reader->createQuery(Post::class, ['changed_field' => 'new', 'page_size' => null])->execute());

        $this->assertSame([$dotted], $this->ids($reader->createQuery(Post::class, ['changed_field' => 'address.street', 'page_size' => null])->execute()));
        $this->assertCount(0, $reader->createQuery(Post::class, ['changed_field' => 'address', 'page_size' => null])->execute());
    }

    /**
     * @param array<Entry> $entries
     *
     * @return list<int>
     */
    private function expectedIds(array $entries, string $field): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $diffs = $entry->getDiffs();
            $matches = \in_array($entry->type, ['associate', 'dissociate'], true)
                ? $field === ($diffs['source']['field'] ?? null)
                : \array_key_exists($field, $diffs);

            if ($matches) {
                $ids[] = (int) $entry->id;
            }
        }

        return $ids;
    }

    /**
     * @param array<Entry> $entries
     *
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        return array_map(static fn (Entry $entry): int => (int) $entry->id, $entries);
    }

    /**
     * @param array<Entry> $entries
     *
     * @return list<string>
     */
    private function types(array $entries): array
    {
        $types = array_values(array_unique(array_map(static fn (Entry $entry): string => $entry->type, $entries)));
        sort($types);

        return $types;
    }

    private function insertRow(string $table, int $schemaVersion, string $type, array $diffs): int
    {
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $jsonCast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';

        $connection->executeStatement(
            \sprintf('INSERT INTO %s (schema_version, type, object_id, diffs, created_at) VALUES (?, ?, ?, ?%s, ?)', $table, $jsonCast),
            [$schemaVersion, $type, '1', json_encode($diffs, JSON_THROW_ON_ERROR), '2020-01-01 00:00:00'],
        );

        return (int) $connection->lastInsertId();
    }
}
