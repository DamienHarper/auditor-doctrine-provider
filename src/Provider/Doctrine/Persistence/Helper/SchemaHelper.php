<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Helper;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Helper\SchemaHelperTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * @see SchemaHelperTest
 */
abstract class SchemaHelper
{
    /**
     * Audit table columns holding potentially large values, stored compressed when compression is enabled.
     *
     * @see Configuration::isCompressionEnabled()
     */
    private const array COMPRESSIBLE_COLUMNS = ['diffs', 'extra_data'];

    /**
     * Returns the names of the audit table columns that are stored compressed when compression is enabled.
     *
     * @return list<string>
     */
    public static function getCompressibleColumns(): array
    {
        return self::COMPRESSIBLE_COLUMNS;
    }

    /**
     * Returns the declaration of a compressible audit table column (without its name), e.g. "JSON COMPRESSED
     * DEFAULT NULL" on MariaDB.
     *
     * The COMPRESSED attribute has to directly follow the data type. DBAL has no column property for it, so it
     * is set through the column definition; compressible columns are nullable and default to NULL, which keeps
     * the declaration identical to the one DBAL generates for the uncompressed column.
     *
     * The declaration is rebuilt from the type only: a charset, collation or comment set on a compressible column
     * would be dropped, so these columns must not have any.
     *
     * @internal
     */
    public static function getCompressibleColumnDeclarationSQL(Column $column, Connection $connection, bool $compressed): string
    {
        \assert(!$column->getNotnull() && null === $column->getDefault(), 'Compressible columns are nullable and default to NULL.');

        return DoctrineHelper::getType($connection, $column->getTypeName())
            ->getSQLDeclaration($column->toArray(true), $connection->getDatabasePlatform())
            .($compressed ? ' COMPRESSED' : '')
            .' DEFAULT NULL'
        ;
    }

    /**
     * Return columns of audit tables.
     *
     * @return array<string, array{type: string, options: array<string, mixed>}>
     */
    public static function getAuditTableColumns(array $defaultTableOptions = []): array
    {
        return [
            'id' => [
                'type' => Types::BIGINT,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'schema_version' => [
                'type' => Types::SMALLINT,
                'options' => [
                    'notnull' => true,
                    'default' => 2,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 11,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'object_id' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'discriminator' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'transaction_id' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length' => 26,
                    'fixed' => true,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'diffs' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'extra_data' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_id' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'blame' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'created_at' => [
                'type' => Types::DATETIME_IMMUTABLE,
                'options' => [
                    'notnull' => true,
                ],
            ],
        ];
    }

    /**
     * Return indices of an audit table.
     *
     * Single-column entries: keyed by the column name.
     * Composite entries: keyed by a '__composite_*' synthetic key with an explicit 'columns' list.
     *
     * Every secondary index slows down each audit INSERT: there is none on object_id or blame_id alone (the
     * composite indexes starting with them serve the same lookups) nor on discriminator (a few distinct values,
     * always filtered along with object_id or created_at). See getObsoleteAuditTableIndexNames().
     *
     * @return array<string, array{type: string, name: string, columns?: list<string>}|array{type: string}>
     */
    public static function getAuditTableIndices(string $tablename): array
    {
        $hash = md5($tablename);

        return [
            'id' => [
                'type' => 'primary',
            ],
            'type' => [
                'type' => 'index',
                'name' => 'type_'.$hash.'_idx',
            ],
            'transaction_id' => [
                'type' => 'index',
                'name' => 'transaction_id_'.$hash.'_idx',
            ],
            'created_at' => [
                'type' => 'index',
                'name' => 'created_at_'.$hash.'_idx',
            ],
            // Composite indexes
            '__composite_object_id_type' => [
                'type' => 'index',
                'columns' => ['object_id', 'type'],
                'name' => 'object_id_type_'.$hash.'_idx',
            ],
            '__composite_blame_id_created_at' => [
                'type' => 'index',
                'columns' => ['blame_id', 'created_at'],
                'name' => 'blame_id_created_at_'.$hash.'_idx',
            ],
        ];
    }

    /**
     * Names of the indices that earlier versions created on an audit table and that audit:schema:update drops:
     * object_id and blame_id alone (redundant with the composite indexes starting with them) and discriminator.
     *
     * @return list<string>
     */
    public static function getObsoleteAuditTableIndexNames(string $tablename): array
    {
        $hash = md5($tablename);

        return [
            'object_id_'.$hash.'_idx',
            'blame_id_'.$hash.'_idx',
            'discriminator_'.$hash.'_idx',
        ];
    }

    /**
     * Names (lowercased) of the columns of a table, e.g. to tell a schema v1 audit table from a v2 one.
     *
     * Only that table is introspected, not the whole database: the cost of Schema introspection grows with the
     * number of tables of the database. $tableName is a name as it appears in SQL, possibly schema-qualified and
     * quoted. An empty list is returned when the table does not exist.
     *
     * @return list<string>
     */
    public static function getTableColumnNames(Connection $connection, string $tableName): array
    {
        $columns = $connection->createSchemaManager()->introspectTableColumns(Parsers::parseOptionallyQualifiedName($tableName));

        return array_map(
            static fn (Column $column): string => mb_strtolower($column->getObjectName()->getIdentifier()->getValue()),
            $columns,
        );
    }

    /**
     * Returns columns of the audit root index table.
     *
     * root_class, root_id and audit_table are 191 characters long and object_id 255, like in the audit tables, so that
     * the composite indices fit in the 3072 bytes InnoDB allows with utf8mb4.
     *
     * @return array<string, array{type: string, options: array<string, mixed>}>
     */
    public static function getRootIndexTableColumns(array $defaultTableOptions = []): array
    {
        $string = static fn (int $length, bool $fixed = false): array => [
            'type' => Types::STRING,
            'options' => [
                'notnull' => true,
                'length' => $length,
                'fixed' => $fixed,
                'platformOptions' => $defaultTableOptions,
            ],
        ];

        return [
            'id' => [
                'type' => Types::BIGINT,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'root_class' => $string(191),
            'root_id' => $string(191),
            'root_path' => $string(255),
            'audit_table' => $string(191),
            'object_id' => $string(255),
            'transaction_id' => $string(26, true),
            'created_at' => [
                'type' => Types::DATETIME_IMMUTABLE,
                'options' => [
                    'notnull' => true,
                ],
            ],
        ];
    }

    /**
     * Returns indices of the audit root index table: entries of a root, and roots of an entry.
     *
     * @return array<string, array{type: string, name: string, columns?: list<string>}|array{type: string}>
     */
    public static function getRootIndexTableIndices(string $tablename): array
    {
        $hash = md5($tablename);

        return [
            'id' => [
                'type' => 'primary',
            ],
            '__composite_root' => [
                'type' => 'index',
                'columns' => ['root_class', 'root_id', 'audit_table', 'transaction_id'],
                'name' => 'root_'.$hash.'_idx',
            ],
            '__composite_entry' => [
                'type' => 'index',
                'columns' => ['audit_table', 'object_id', 'transaction_id'],
                'name' => 'entry_'.$hash.'_idx',
            ],
        ];
    }

    public static function isValidPayload(array $payload): bool
    {
        return array_all(array_keys(self::getAuditTableColumns()), static fn (string $columnName): bool => 'id' === $columnName || \array_key_exists($columnName, $payload));
    }

    /**
     * Builds an audit table column from its array definition.
     *
     * When the definition configures empty platformOptions, the charset and collation of the existing
     * column are preserved: re-adding the column without them produces a schema that differs from the
     * introspected one, causing a false-positive ALTER TABLE on every run.
     *
     * @see https://github.com/DamienHarper/auditor/issues/276
     *
     * @internal
     */
    public static function buildColumn(string $columnName, string $type, array $options, ?Column $currentColumn = null): Column
    {
        $editor = Column::editor()
            ->setUnquotedName($columnName)
            ->setTypeName($type)
        ;

        if (\array_key_exists('length', $options)) {
            $editor->setLength($options['length']);
        }

        if (\array_key_exists('fixed', $options)) {
            $editor->setFixed($options['fixed']);
        }

        if (\array_key_exists('notnull', $options)) {
            $editor->setNotNull($options['notnull']);
        }

        if (\array_key_exists('default', $options)) {
            $editor->setDefaultValue($options['default']);
        }

        if (\array_key_exists('autoincrement', $options)) {
            $editor->setAutoincrement($options['autoincrement']);
        }

        if (\array_key_exists('unsigned', $options)) {
            $editor->setUnsigned($options['unsigned']);
        }

        if (isset($options['platformOptions']) && [] === $options['platformOptions'] && $currentColumn instanceof Column) {
            $editor->setCharset($currentColumn->getCharset())->setCollation($currentColumn->getCollation());
        } else {
            $editor
                ->setCharset($options['platformOptions']['charset'] ?? null)
                ->setCollation($options['platformOptions']['collation'] ?? null)
            ;
        }

        return $editor->create();
    }

    /**
     * Converts the PostgreSQL JSONB columns of an introspected table to the JSONB type.
     *
     * DBAL 4 introspects JSONB columns as JSON columns carrying the "jsonb" platform option, which is
     * deprecated (doctrine/dbal#6939): comparing such a column triggers a deprecation.
     *
     * @internal
     */
    public static function normalizeJsonbColumns(Table $table): Table
    {
        $jsonbColumns = array_filter(
            $table->getColumns(),
            // @phpstan-ignore method.deprecated (no other way to read the jsonb option set by DBAL 4 introspection)
            static fn (Column $column): bool => true === ($column->getPlatformOptions()['jsonb'] ?? false),
        );

        if ([] === $jsonbColumns) {
            return $table;
        }

        $editor = $table->edit();
        foreach ($jsonbColumns as $column) {
            // Column::edit() does not carry over the "jsonb" platform option
            $editor->modifyColumn($column->getObjectName(), static function (ColumnEditor $columnEditor): void {
                $columnEditor->setTypeName(Types::JSONB);
            });
        }

        return $editor->create();
    }
}
