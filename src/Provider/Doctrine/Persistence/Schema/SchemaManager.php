<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Schema;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\PlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Schema\SchemaManagerTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

/**
 * Builds, compares and updates audit tables.
 *
 * Two code paths coexist:
 * - DBAL 4.5+: the schema editor API (Schema::edit(), Table::edit()...). Schema objects are then
 *   immutable: createAuditTable() and updateAuditTable() return a new Schema and leave the given one untouched.
 * - DBAL < 4.5: the legacy mutable API. The given Schema is modified in place and returned.
 *
 * @see SchemaManagerTest
 */
final readonly class SchemaManager
{
    public function __construct(private DoctrineProvider $provider) {}

    public function updateAuditSchema(?array $sqls = null, ?callable $callback = null): void
    {
        if (null === $sqls) {
            $sqls = $this->getUpdateAuditSchemaSql();
        }

        /** @var StorageService[] $storageServices */
        $storageServices = $this->provider->getStorageServices();
        foreach ($sqls as $name => $queries) {
            $connection = $storageServices[$name]->getEntityManager()->getConnection();
            foreach ($queries as $index => $sql) {
                $statement = $connection->prepare($sql);
                $statement->executeStatement();

                if (null !== $callback) {
                    $callback([
                        'total' => \count($sqls),
                        'current' => $index,
                    ]);
                }
            }
        }
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     * Only auditable entities are considered.
     */
    public function getAuditableTableNames(EntityManagerInterface $entityManager): array
    {
        $metadataDriver = $entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities = [];
        if ($metadataDriver instanceof MappingDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }

        $audited = [];
        foreach ($entities as $entity) {
            if ($this->provider->isAuditable($entity)) {
                $audited[$entity] = $entityManager->getClassMetadata($entity)->getTableName();
            }
        }

        ksort($audited);

        return $audited;
    }

    public function collectAuditableEntities(): array
    {
        // auditable entities by storage entity manager
        $repository = [];

        /** @var AuditingService[] $auditingServices */
        $auditingServices = $this->provider->getAuditingServices();
        foreach ($auditingServices as $auditingService) {
            $classes = $this->getAuditableTableNames($auditingService->getEntityManager());
            // Populate the auditable entities repository
            foreach ($classes as $entity => $tableName) {
                $storageService = $this->provider->getStorageServiceForEntity($entity);
                $key = array_search($storageService, $this->provider->getStorageServices(), true);
                if (!isset($repository[$key])) {
                    $repository[$key] = [];
                }

                $repository[$key][$entity] = $tableName;
            }
        }

        return $repository;
    }

    /**
     * Computes the SQL queries that bring audit tables to their expected structure, by storage service.
     *
     * Only audit tables are compared: the other tables of the storage database are left out, so that
     * comparing them can neither generate statements nor trigger deprecations.
     */
    public function getUpdateAuditSchemaSql(): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        /** @var StorageService[] $storageServices */
        $storageServices = $this->provider->getStorageServices();

        // Collect auditable entities from auditing entity managers
        $repository = $this->collectAuditableEntities();

        // Compute and collect SQL queries
        $sqls = [];
        foreach ($repository as $name => $classes) {
            $connection = $storageServices[$name]->getEntityManager()->getConnection();
            $storageSchemaManager = $connection->createSchemaManager();
            $platform = $connection->getDatabasePlatform();
            $storageSchema = $storageSchemaManager->introspectSchema();
            $schemaConfig = $storageSchemaManager->createSchemaConfig();
            $comparator = $storageSchemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));

            $createdNamespaces = [];
            $createdTables = [];
            $alterSqls = [];
            $processed = [];
            foreach (array_keys($classes) as $entityFQCN) {
                /** @var string $auditTablename */
                $auditTablename = $this->resolveAuditTableName($entityFQCN, $configuration);

                // Several entities may share an audit table (single table inheritance)
                $key = mb_strtolower($auditTablename);
                if (isset($processed[$key])) {
                    continue;
                }

                $processed[$key] = true;

                if ($storageSchema->hasTable($auditTablename)) {
                    // Audit table exists, let's update it if needed
                    $currentTable = $this->normalizeJsonbColumns($storageSchema->getTable($auditTablename), $connection);
                    $expectedTable = $this->applyAuditTableStructure(
                        DoctrineHelper::hasSchemaEditorApi() ? $currentTable : clone $currentTable,
                        SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
                        SchemaHelper::getAuditTableIndices($auditTablename),
                        $connection,
                    );
                    $tableDiff = $comparator->compareTables($currentTable, $expectedTable);
                    if (!$tableDiff->isEmpty()) {
                        array_push($alterSqls, ...$platform->getAlterTableSQL($tableDiff));
                    }
                } else {
                    // Audit table does not exist, let's create it
                    $auditTable = $this->buildAuditTable($auditTablename, $connection, $schemaConfig);
                    $namespace = $this->getNamespaceName($auditTable);
                    if (
                        null !== $namespace
                        && $platform->supportsSchemas()
                        && 0 !== strcasecmp($namespace, (string) $schemaConfig->getName())
                        && !$storageSchema->hasNamespace($namespace)
                    ) {
                        $createdNamespaces[mb_strtolower($namespace)] = $namespace;
                    }

                    $createdTables[] = $auditTable;
                }
            }

            $sqls[$name] = [
                ...array_values(array_map($platform->getCreateSchemaSQL(...), $createdNamespaces)),
                ...([] === $createdTables ? [] : $platform->getCreateTablesSQL($createdTables)),
                ...$alterSqls,
            ];
        }

        return $sqls;
    }

    /**
     * Adds the audit table of an entity to a schema, if missing.
     *
     * With DBAL 4.5+, the given schema is left untouched and a new schema is returned.
     * With older DBAL versions, the given schema is modified in place and returned.
     *
     * @throws Exception
     */
    public function createAuditTable(string $entity, ?Schema $schema = null): Schema
    {
        $storageService = $this->provider->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();

        if (!$schema instanceof Schema) {
            $schema = $connection->createSchemaManager()->introspectSchema();
        }

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $auditTablename = $this->resolveAuditTableName($entity, $configuration);

        if (null === $auditTablename || $schema->hasTable($auditTablename)) {
            return $schema;
        }

        if (!DoctrineHelper::hasSchemaEditorApi()) {
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $this->populateLegacyAuditTable($schema->createTable($auditTablename), $auditTablename, $connection);

            return $schema;
        }

        $auditTable = $this->buildAuditTable($auditTablename, $connection, $connection->createSchemaManager()->createSchemaConfig());

        return $schema->edit()->addTable($auditTable)->create();
    }

    /**
     * Adds the audit table of an entity to a schema in place, if missing, with the mutable schema API.
     *
     * Only meant for schemas owned by a caller that keeps using the mutable API on them (e.g. the ORM schema
     * tool when it does not use the schema editor API, which silences the related DBAL deprecations itself).
     *
     * @internal
     *
     * @throws Exception
     */
    public function addAuditTableToSchema(string $entity, Schema $schema): void
    {
        $storageService = $this->provider->getStorageServiceForEntity($entity);

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $auditTablename = $this->resolveAuditTableName($entity, $configuration);

        if (null === $auditTablename || $schema->hasTable($auditTablename)) {
            return;
        }

        $this->populateLegacyAuditTable(
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $schema->createTable($auditTablename),
            $auditTablename,
            $storageService->getEntityManager()->getConnection(),
        );
    }

    /**
     * Ensures an audit table's structure is valid.
     *
     * With DBAL 4.5+, the given schema is left untouched and a new schema is returned.
     * With older DBAL versions, the given schema is modified in place and returned.
     *
     * @throws SchemaException
     * @throws Exception
     */
    public function updateAuditTable(string $entity, ?Schema $schema = null): Schema
    {
        $storageService = $this->provider->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();

        $schemaManager = $connection->createSchemaManager();
        if (!$schema instanceof Schema) {
            $schema = $schemaManager->introspectSchema();
        }

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        $auditTablename = $this->resolveAuditTableName($entity, $configuration);
        \assert(\is_string($auditTablename));
        $table = $schema->getTable($auditTablename);

        $updatedTable = $this->applyAuditTableStructure(
            $table,
            SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
            SchemaHelper::getAuditTableIndices($auditTablename),
            $connection,
        );

        if ($updatedTable === $table) {
            // DBAL < 4.5: the table has been modified in place
            return $schema;
        }

        return $schema->edit()
            ->dropTable($table->getObjectName())
            ->addTable($updatedTable)
            ->create()
        ;
    }

    /**
     * Resolves table name, including namespace/schema.
     *
     * The dot (.) separator is always used regardless of whether the platform
     * "supports schemas" in the Doctrine DBAL sense. Platforms such as MySQL/MariaDB
     * do not support PostgreSQL-style schemas ($platform->supportsSchemas() === false),
     * but they do support cross-database access via the `database.table` dot notation.
     * Using `__` instead of `.` (as Doctrine does internally for schema emulation)
     * produces table names that do not exist, breaking both regular entity queries and
     * audit table lookups.
     *
     * @see https://github.com/DamienHarper/auditor/issues/236
     */
    public function resolveTableName(string $tableName, string $namespaceName): string
    {
        if ('' === $namespaceName || '0' === $namespaceName) {
            return $tableName;
        }

        return $namespaceName.'.'.$tableName;
    }

    /**
     * Resolves audit table name, including namespace/schema.
     */
    public function resolveAuditTableName(string $entity, Configuration $configuration): ?string
    {
        $entities = $configuration->getEntities();
        $entityOptions = $entities[$entity];
        $tablename = $this->resolveTableName($entityOptions['table_name'], $entityOptions['audit_table_schema']);

        return $this->computeAuditTablename($tablename, $configuration);
    }

    /**
     * Computes audit table name **without** namespace/schema.
     */
    public function computeAuditTablename(string $entityTableName, Configuration $configuration): ?string
    {
        $prefix = $configuration->getTablePrefix();
        $suffix = $configuration->getTableSuffix();

        // For performance reasons, we only process the table name with preg_replace_callback and a regex
        // if the entity's table name contains a dot, a quote or a backtick
        if (!str_contains($entityTableName, '.') && !str_contains($entityTableName, '"') && !str_contains($entityTableName, '`')) {
            return $prefix.$entityTableName.$suffix;
        }

        return preg_replace_callback(
            '#^(?:(["`])?([^."`]+)["`]?\.)?(["`]?)([^."`]+)["`]?$#',
            static function (array $matches) use ($prefix, $suffix): string {
                $schemaDelimiter = $matches[1];     // Opening schema quote/backtick
                $schema = $matches[2];              // Captures raw schema name (if exists)
                $tableDelimiter = $matches[3];      // Opening table quote/backtick
                $tableName = $matches[4];           // Captures raw table name

                $newTableName = $prefix.$tableName.$suffix;

                if ('"' === $tableDelimiter || '`' === $tableDelimiter) {
                    $newTableName = $tableDelimiter.$newTableName.$tableDelimiter;
                }

                if ('' !== $schema && '0' !== $schema) {
                    if ('"' === $schemaDelimiter || '`' === $schemaDelimiter) {
                        $schema = $schemaDelimiter.$schema.$schemaDelimiter;
                    }

                    return $schema.'.'.$newTableName;
                }

                return $newTableName;
            },
            $entityTableName
        );
    }

    /**
     * Returns the type of an audit table column: JSON columns fall back to TEXT when the platform does not
     * support JSON.
     */
    private function resolveColumnType(string $type, bool $isJsonSupported): string
    {
        return !$isJsonSupported && \in_array($type, DoctrineHelper::jsonStringTypes(), true) ? Types::TEXT : $type;
    }

    private function getColumnName(Column $column): string
    {
        // AbstractAsset::getName() is deprecated as of DBAL 4.4
        return method_exists($column, 'getObjectName')
            ? $column->getObjectName()->getIdentifier()->getValue()
            // @phpstan-ignore method.deprecated (DBAL < 4.4 compatibility path)
            : $column->getName();
    }

    private function getNamespaceName(Table $table): ?string
    {
        return $table->getObjectName()->getQualifier()?->getValue();
    }

    /**
     * Builds a new audit table, detached from any schema.
     *
     * @throws Exception
     */
    private function buildAuditTable(string $auditTablename, Connection $connection, SchemaConfig $schemaConfig): Table
    {
        if (!DoctrineHelper::hasSchemaEditorApi()) {
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $table = new Schema([], [], $schemaConfig)->createTable($auditTablename);
            $this->populateLegacyAuditTable($table, $auditTablename, $connection);

            return $table;
        }

        $editor = Table::editor()
            ->setName(Parsers::parseOptionallyQualifiedName($auditTablename))
            // Schema::createTable() used to apply the default table options of the schema configuration
            ->setOptions($schemaConfig->getDefaultTableOptions())
            ->setConfiguration($schemaConfig->toTableConfiguration())
        ;

        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        foreach (SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)) as $columnName => $struct) {
            $editor->addColumn($this->buildColumn($columnName, $this->resolveColumnType($struct['type'], $isJsonSupported), $struct['options']));
        }

        $this->addAuditTableIndices($editor, null, SchemaHelper::getAuditTableIndices($auditTablename), $connection);

        return $editor->create();
    }

    /**
     * Brings an existing audit table to the given structure.
     *
     * With DBAL 4.5+, returns a new table and leaves the given one untouched.
     * With older DBAL versions, modifies the given table in place and returns it.
     *
     * @param array<string, array{type: string, options: array<string, mixed>}>                   $expectedColumns
     * @param array<string, array{type: string, name?: string, columns?: non-empty-list<string>}> $expectedIndices
     *
     * @throws SchemaException
     */
    private function applyAuditTableStructure(Table $table, array $expectedColumns, array $expectedIndices, Connection $connection): Table
    {
        if (!DoctrineHelper::hasSchemaEditorApi()) {
            $this->processLegacyColumns($table, $table->getColumns(), $expectedColumns, $connection);
            $this->processLegacyIndices($table, $expectedIndices, $connection);

            return $table;
        }

        $editor = $table->edit();
        $isJsonSupported = PlatformHelper::isJsonSupported($connection);

        $processed = [];
        foreach ($table->getColumns() as $column) {
            $columnName = $this->getColumnName($column);
            $editor->dropColumn($column->getObjectName());

            // column is part of expected columns: re-add it with its expected definition,
            // otherwise it has to be removed
            if (\array_key_exists($columnName, $expectedColumns)) {
                $editor->addColumn($this->buildColumn(
                    $columnName,
                    $this->resolveColumnType($expectedColumns[$columnName]['type'], $isJsonSupported),
                    $expectedColumns[$columnName]['options'],
                    $column,
                ));
            }

            $processed[] = $columnName;
        }

        foreach ($expectedColumns as $columnName => $struct) {
            if (!\in_array($columnName, $processed, true)) {
                $editor->addColumn($this->buildColumn($columnName, $struct['type'], $struct['options']));
            }
        }

        $this->addAuditTableIndices($editor, $table, $expectedIndices, $connection);

        return $editor->create();
    }

    /**
     * Adds the primary key and indices of an audit table (DBAL 4.5+), replacing the ones of $currentTable.
     *
     * @param array<string, array{type: string, name?: string, columns?: non-empty-list<string>}> $expectedIndices
     */
    private function addAuditTableIndices(TableEditor $editor, ?Table $currentTable, array $expectedIndices, Connection $connection): void
    {
        foreach ($expectedIndices as $columnName => $struct) {
            \assert(\is_string($columnName));
            if ('primary' === $struct['type']) {
                if ($currentTable?->getPrimaryKeyConstraint() instanceof PrimaryKeyConstraint) {
                    $editor->dropPrimaryKeyConstraint();
                }

                $editor->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()
                        ->setUnquotedColumnNames($columnName)
                        ->setIsClustered(true)
                        ->create()
                );
            } elseif (isset($struct['name'])) {
                // Composite indexes carry an explicit 'columns' list; single-column indexes derive
                // from the array key (which is the column name for non-composite entries).
                $columns = $struct['columns'] ?? [$columnName];

                if ($currentTable instanceof Table && $currentTable->hasIndex($struct['name'])) {
                    $editor->dropIndex($currentTable->getIndex($struct['name'])->getObjectName());
                }

                $indexEditor = Index::editor()->setUnquotedName($struct['name']);
                // Length limiting only applies to single string columns, not composites
                $isLengthLimited = 1 === \count($columns) && PlatformHelper::isIndexLengthLimited($columns[0], $connection);
                foreach ($columns as $column) {
                    $indexEditor->addUnquotedColumnName($column, $isLengthLimited ? 191 : null);
                }

                $editor->addIndex($indexEditor->create());
            } else {
                throw new InvalidArgumentException(\sprintf("Missing key 'name' for column '%s'", $columnName));
            }
        }
    }

    /**
     * Builds an audit table column from its legacy array definition (DBAL 4.5+).
     *
     * When the definition configures empty platformOptions, the charset and collation of the existing
     * column are preserved: re-adding the column without them produces a schema that differs from the
     * introspected one, causing a false-positive ALTER TABLE on every run.
     *
     * @see https://github.com/DamienHarper/auditor/issues/276
     */
    private function buildColumn(string $columnName, string $type, array $options, ?Column $currentColumn = null): Column
    {
        $editor = Column::editor()
            ->setUnquotedName($columnName)
            ->setTypeName($type)
        ;

        if (\array_key_exists('length', $options)) {
            $editor->setLength($options['length']);
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
     */
    private function normalizeJsonbColumns(Table $table, Connection $connection): Table
    {
        if (!\defined(Types::class.'::JSONB')) {
            return $table;
        }

        $jsonbColumns = array_filter(
            $table->getColumns(),
            // @phpstan-ignore method.deprecated (no other way to read the jsonb option set by DBAL 4 introspection)
            static fn (Column $column): bool => true === ($column->getPlatformOptions()['jsonb'] ?? false),
        );

        if ([] === $jsonbColumns) {
            return $table;
        }

        if (DoctrineHelper::hasSchemaEditorApi()) {
            $editor = $table->edit();
            foreach ($jsonbColumns as $column) {
                // Column::edit() does not carry over the "jsonb" platform option
                $editor->modifyColumn($column->getObjectName(), static function (ColumnEditor $columnEditor): void {
                    $columnEditor->setTypeName(Types::JSONB);
                });
            }

            return $editor->create();
        }

        $table = clone $table;
        foreach ($jsonbColumns as $column) {
            $column = $table->getColumn($this->getColumnName($column));
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $column->setType(DoctrineHelper::getType($connection, Types::JSONB));
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $platformOptions = $column->getPlatformOptions();
            unset($platformOptions['jsonb']);
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $column->setPlatformOptions($platformOptions);
        }

        return $table;
    }

    /**
     * Adds the columns, primary key and indices of an audit table (DBAL < 4.5).
     */
    private function populateLegacyAuditTable(Table $auditTable, string $auditTablename, Connection $connection): void
    {
        // Add columns to audit table
        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        foreach (SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)) as $columnName => $struct) {
            // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
            $auditTable->addColumn($columnName, $this->resolveColumnType($struct['type'], $isJsonSupported), $struct['options']);
        }

        // Add indices to audit table
        foreach (SchemaHelper::getAuditTableIndices($auditTablename) as $columnName => $struct) {
            \assert(\is_string($columnName));
            if ('primary' === $struct['type']) {
                DoctrineHelper::setPrimaryKey($auditTable, $columnName);
            } elseif (isset($struct['name'])) {
                $columns = $struct['columns'] ?? [$columnName];
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $auditTable->addIndex(
                    $columns,
                    $struct['name'],
                    [],
                    1 === \count($columns) && PlatformHelper::isIndexLengthLimited($columns[0], $connection) ? ['lengths' => [191]] : []
                );
            } else {
                throw new InvalidArgumentException(\sprintf("Missing key 'name' for column '%s'", $columnName));
            }
        }
    }

    /**
     * @param Column[] $columns
     */
    private function processLegacyColumns(Table $table, array $columns, array $expectedColumns, Connection $connection): void
    {
        $processed = [];

        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        foreach ($columns as $column) {
            $columnName = $this->getColumnName($column);
            if (\array_key_exists($columnName, $expectedColumns)) {
                // column is part of expected columns
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->dropColumn($columnName);

                $type = $this->resolveColumnType($expectedColumns[$columnName]['type'], $isJsonSupported);
                $columnOptions = $expectedColumns[$columnName]['options'];

                // Preserve the column's existing platformOptions (e.g. MySQL charset/collation)
                // when the expected definition does not configure any. Without this, re-adding
                // the column with platformOptions: [] produces a schema that differs from the
                // introspected one, causing a false-positive ALTER TABLE on every run.
                // @see https://github.com/DamienHarper/auditor/issues/276
                if (isset($columnOptions['platformOptions']) && [] === $columnOptions['platformOptions']) {
                    // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                    $columnOptions['platformOptions'] = $column->getPlatformOptions();
                }

                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->addColumn($columnName, $type, $columnOptions);
            } else {
                // column is not part of expected columns so it has to be removed
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->dropColumn($columnName);
            }

            $processed[] = $columnName;
        }

        foreach ($expectedColumns as $columnName => $options) {
            if (!\in_array($columnName, $processed, true)) {
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->addColumn($columnName, $options['type'], $options['options']);
            }
        }
    }

    /**
     * @throws SchemaException
     */
    private function processLegacyIndices(Table $table, array $expectedIndices, Connection $connection): void
    {
        foreach ($expectedIndices as $columnName => $options) {
            \assert(\is_string($columnName));
            if ('primary' === $options['type']) {
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->dropPrimaryKey();
                DoctrineHelper::setPrimaryKey($table, $columnName);
            } else {
                if ($table->hasIndex($options['name'])) {
                    // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                    $table->dropIndex($options['name']);
                }

                $columns = $options['columns'] ?? [$columnName];
                // @phpstan-ignore method.deprecated (DBAL < 4.5 compatibility path)
                $table->addIndex(
                    $columns,
                    $options['name'],
                    [],
                    1 === \count($columns) && PlatformHelper::isIndexLengthLimited($columns[0], $connection) ? ['lengths' => [191]] : []
                );
            }
        }
    }
}
