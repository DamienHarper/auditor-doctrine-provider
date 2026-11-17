<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use DH\Auditor\Tests\Traits\ReflectionTrait;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #276: audit:schema:update always produces ALTER TABLE queries
 * on MySQL even when the audit table is already up-to-date.
 *
 * Root cause: updating an audit table dropped and re-added ALL existing STRING columns with
 * platformOptions: [] (no charset/collation). MySQL introspects those columns with
 * explicit charset/collation in their platformOptions. The Doctrine schema comparator
 * therefore always sees a difference and emits a no-op CHANGE column statement.
 *
 * Fix: when the expected platformOptions are empty ([]), preserve the column's existing
 * charset/collation so the desired schema stays identical to the introspected one.
 *
 * @see https://github.com/DamienHarper/auditor/issues/276
 *
 * @internal
 */
#[Small]
final class Issue276Test extends TestCase
{
    use DefaultSchemaSetupTrait;
    use ReflectionTrait;

    private const string CHARSET = 'utf8mb4';

    private const string COLLATION = 'utf8mb4_0900_ai_ci';

    /**
     * Updating an audit table must not clear the charset/collation of existing STRING columns when the
     * expected column definition does not specify any (platformOptions: []).
     *
     * On MySQL, introspected columns carry explicit charset/collation in their platformOptions.
     * Clearing them causes a false-positive ALTER TABLE on every audit:schema:update run.
     */
    public function testUpdatePreservesExistingCharsetAndCollationWhenNoneAreConfigured(): void
    {
        // Build a table that looks like a MySQL-introspected audit table: every STRING column
        // carries explicit charset + collation.
        $table = $this->buildTable(SchemaHelper::getAuditTableColumns(), withCharsetAndCollation: true);

        // getAuditTableColumns() with no argument returns platformOptions: [] for STRING columns,
        // which is the case for MySQL users who have NOT configured defaultTableOptions.
        $updatedTable = $this->applyAuditTableStructure($table);

        $stringColumnNames = array_keys(array_filter(
            SchemaHelper::getAuditTableColumns(),
            static fn (array $col): bool => Types::STRING === $col['type']
        ));

        foreach ($stringColumnNames as $columnName) {
            $this->assertSame(
                [self::CHARSET, self::COLLATION],
                $this->getCharsetAndCollation($updatedTable->getColumn($columnName)),
                \sprintf(
                    'Charset and collation of STRING column "%s" must be preserved when expected options do not override them (#276).',
                    $columnName
                )
            );
        }
    }

    /**
     * Updating an audit table must still add new columns that are missing from the table —
     * the fix for #276 must not prevent legitimate schema updates (e.g. adding extra_data).
     */
    public function testUpdateStillAddsNewColumnsWhenMissing(): void
    {
        // Build a table that is missing the extra_data column (simulating a pre-v4 audit table).
        $columns = SchemaHelper::getAuditTableColumns();
        unset($columns['extra_data']);
        $table = $this->buildTable($columns, withCharsetAndCollation: false);

        $this->assertFalse($table->hasColumn('extra_data'), 'Precondition: extra_data must not exist before the update.');

        $updatedTable = $this->applyAuditTableStructure($table);

        $this->assertTrue($updatedTable->hasColumn('extra_data'), 'Updating an audit table must add missing columns (extra_data) even after the #276 fix.');
    }

    private function applyAuditTableStructure(Table $table): Table
    {
        $connection = $this->provider->getStorageServiceForEntity(Post::class)
            ->getEntityManager()
            ->getConnection()
        ;

        $schemaManager = new SchemaManager($this->provider);
        $method = $this->reflectMethod($schemaManager, 'applyAuditTableStructure');

        $updatedTable = $method->invoke($schemaManager, $table, SchemaHelper::getAuditTableColumns(), [], $connection);
        $this->assertInstanceOf(Table::class, $updatedTable);

        return $updatedTable;
    }

    /**
     * @param array<string, array{type: string, options: array<string, mixed>}> $columns
     */
    private function buildTable(array $columns, bool $withCharsetAndCollation): Table
    {
        if (!method_exists(Schema::class, 'edit')) {
            // DBAL < 4.5
            $table = new Table('post_audit');
            foreach ($columns as $columnName => $struct) {
                $options = $struct['options'];
                if ($withCharsetAndCollation && Types::STRING === $struct['type']) {
                    $options['platformOptions'] = ['charset' => self::CHARSET, 'collation' => self::COLLATION];
                }

                $table->addColumn($columnName, $struct['type'], $options);
            }

            return $table;
        }

        $editor = Table::editor()->setUnquotedName('post_audit');
        foreach ($columns as $columnName => $struct) {
            $columnEditor = Column::editor()
                ->setUnquotedName($columnName)
                ->setTypeName($struct['type'])
                ->setLength($struct['options']['length'] ?? null)
                ->setNotNull($struct['options']['notnull'] ?? true)
            ;

            if ($withCharsetAndCollation && Types::STRING === $struct['type']) {
                $columnEditor->setCharset(self::CHARSET)->setCollation(self::COLLATION);
            }

            $editor->addColumn($columnEditor->create());
        }

        return $editor->create();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function getCharsetAndCollation(Column $column): array
    {
        if (method_exists($column, 'getCharset')) {
            return [$column->getCharset(), $column->getCollation()];
        }

        // DBAL < 4.5
        $platformOptions = $column->getPlatformOptions();

        return [$platformOptions['charset'] ?? null, $platformOptions['collation'] ?? null];
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Post::class => ['enabled' => true],
        ]);
    }
}
