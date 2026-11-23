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
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

/**
 * Builds, compares and updates audit tables with the DBAL schema editor API.
 *
 * Schema objects are immutable: createAuditTable() and updateAuditTable() return a new Schema and leave the
 * given one untouched.
 *
 * @see SchemaManagerTest
 */
final readonly class SchemaManager
{
    public function __construct(private DoctrineProvider $provider) {}

    /**
     * Returns the names of audit tables that still carry the legacy v1 schema (transaction_hash
     * column present). Used by UpdateSchemaCommand to refuse execution until migration is done.
     *
     * @return list<string>
     */
    public function collectLegacyAuditTables(): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        /** @var StorageService[] $storageServices */
        $storageServices = $this->provider->getStorageServices();

        $legacy = [];

        foreach ($storageServices as $storageService) {
            $connection = $storageService->getEntityManager()->getConnection();
            $schema = $connection->createSchemaManager()->introspectSchema();

            foreach (array_keys($configuration->getEntities()) as $entity) {
                $auditTableName = $this->resolveAuditTableName($entity, $configuration);
                if (null === $auditTableName) {
                    continue;
                }

                if (!$schema->hasTable($auditTableName)) {
                    continue;
                }

                if ($schema->getTable($auditTableName)->hasColumn('transaction_hash')) {
                    $legacy[] = $auditTableName;
                }
            }
        }

        return array_values(array_unique($legacy));
    }

    public function updateAuditSchema(?array $sqls = null, ?callable $callback = null): void
    {
        $sqls ??= $this->getUpdateAuditSchemaSql();

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
     * Only auditable entities with an audit table are considered.
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
            if ($this->provider->hasAuditTable($entity)) {
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
                $repository[$key] ??= [];

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

            $isCompressionEnabled = $this->isCompressionEnabled($connection);

            /** @var array<string, array<string, array<string, true>>> $compressedColumns compressed columns by table, by schema */
            $compressedColumns = [];

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
                    $currentTable = SchemaHelper::normalizeJsonbColumns($storageSchema->getTable($auditTablename));
                    $expectedTable = $this->applyAuditTableStructure(
                        $currentTable,
                        SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
                        SchemaHelper::getAuditTableIndices($auditTablename),
                        $connection,
                        SchemaHelper::getObsoleteAuditTableIndexNames($auditTablename),
                    );
                    $tableDiff = $comparator->compareTables($currentTable, $expectedTable);
                    if (!$tableDiff->isEmpty()) {
                        array_push($alterSqls, ...$platform->getAlterTableSQL($tableDiff));
                    }

                    if (PlatformHelper::supportsColumnCompression($connection)) {
                        // DBAL does not introspect the COMPRESSED attribute: read it from information_schema, once per schema
                        $tableName = $currentTable->getObjectName();
                        $schemaName = $tableName->getQualifier()?->getValue() ?? (string) $connection->getDatabase();
                        $compressedColumns[$schemaName] ??= $this->getCompressedColumns($connection, $schemaName);

                        $sql = $this->getColumnCompressionSql(
                            $connection,
                            $expectedTable,
                            $tableDiff,
                            $isCompressionEnabled,
                            $compressedColumns[$schemaName][mb_strtolower($tableName->getUnqualifiedName()->getValue())] ?? [],
                        );
                        if (null !== $sql) {
                            $alterSqls[] = $sql;
                        }
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

            // The audit root index lives next to the audit tables of the entities that declare roots.
            $hasRoots = array_any(array_keys($classes), static fn (int|string $entityFQCN): bool => $configuration->hasRoots((string) $entityFQCN));
            if ($hasRoots) {
                $rootIndexTablename = $this->rootIndexTableName();
                if ($storageSchema->hasTable($rootIndexTablename)) {
                    $currentTable = $storageSchema->getTable($rootIndexTablename);
                    $expectedTable = $this->applyAuditTableStructure(
                        $currentTable,
                        SchemaHelper::getRootIndexTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
                        SchemaHelper::getRootIndexTableIndices($rootIndexTablename),
                        $connection,
                    );
                    $tableDiff = $comparator->compareTables($currentTable, $expectedTable);
                    if (!$tableDiff->isEmpty()) {
                        array_push($alterSqls, ...$platform->getAlterTableSQL($tableDiff));
                    }
                } else {
                    $createdTables[] = $this->buildRootIndexTable($connection, $schemaConfig);
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
     * Adds the audit table of an entity to a schema, if missing: the given schema is left untouched and a new
     * schema is returned.
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

        $schemaConfig = $connection->createSchemaManager()->createSchemaConfig();
        if ($configuration->hasRoots($entity) && !$schema->hasTable($this->rootIndexTableName())) {
            $schema = $schema->edit()->addTable($this->buildRootIndexTable($connection, $schemaConfig))->create();
        }

        if (null === $auditTablename || $schema->hasTable($auditTablename)) {
            return $schema;
        }

        $auditTable = $this->buildAuditTable($auditTablename, $connection, $schemaConfig);

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

        if ($configuration->hasRoots($entity) && !$schema->hasTable($this->rootIndexTableName())) {
            $this->populateLegacyAuditTable(
                // @phpstan-ignore method.deprecated (mutable schema of an ORM not using the schema editor API)
                $schema->createTable($this->rootIndexTableName()),
                $this->rootIndexTableName(),
                $storageService->getEntityManager()->getConnection(),
                true,
            );
        }

        if (null === $auditTablename || $schema->hasTable($auditTablename)) {
            return;
        }

        $this->populateLegacyAuditTable(
            // @phpstan-ignore method.deprecated (mutable schema of an ORM not using the schema editor API)
            $schema->createTable($auditTablename),
            $auditTablename,
            $storageService->getEntityManager()->getConnection(),
        );
    }

    /**
     * Ensures an audit table's structure is valid: the given schema is left untouched and a new schema is
     * returned.
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
            SchemaHelper::getObsoleteAuditTableIndexNames($auditTablename),
        );

        return $schema->edit()
            ->dropTable($table->getObjectName())
            ->addTable($updatedTable)
            ->create()
        ;
    }

    /**
     * Name of the audit root index table.
     */
    public function rootIndexTableName(): string
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        return $configuration->getRootIndexTableName();
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
     * Whether the compressible columns of audit tables stored through the given connection are compressed.
     */
    private function isCompressionEnabled(Connection $connection): bool
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        return $configuration->isCompressionEnabled() && PlatformHelper::supportsColumnCompression($connection);
    }

    /**
     * Returns the column declared as compressed if it is a compressible column and compression is enabled,
     * the column itself otherwise.
     *
     * The DBAL schema comparator ignores column definitions and does not introspect the COMPRESSED attribute:
     * declaring it does not make schema diffs (doctrine:schema:update, doctrine:migrations:diff,
     * audit:schema:update) unstable. The compression of existing columns is handled by getColumnCompressionSql().
     */
    private function compressColumn(Column $column, Connection $connection, bool $isCompressionEnabled): Column
    {
        if (!$isCompressionEnabled || !\in_array($column->getObjectName()->getIdentifier()->getValue(), SchemaHelper::getCompressibleColumns(), true)) {
            return $column;
        }

        return $column->edit()
            ->setColumnDefinition(SchemaHelper::getCompressibleColumnDeclarationSQL($column, $connection, true))
            ->create()
        ;
    }

    /**
     * Returns the statement that compresses (or decompresses) the compressible columns of an existing audit
     * table whose compression does not match the configuration, if any.
     *
     * DBAL does not introspect the COMPRESSED attribute (columns are compared by data type), so the actual
     * compression of columns is read from information_schema. Columns already altered by the table diff are
     * skipped: their new declaration already reflects the configuration. Altering a column rewrites the table,
     * which (de)compresses existing rows.
     *
     * @param array<string, true> $compressedColumns currently compressed columns of the table (lowercased names)
     */
    private function getColumnCompressionSql(Connection $connection, Table $expectedTable, TableDiff $tableDiff, bool $isCompressionEnabled, array $compressedColumns): ?string
    {
        $platform = $connection->getDatabasePlatform();

        $alteredColumns = array_map(
            static fn (Column $column): string => mb_strtolower($column->getObjectName()->getIdentifier()->getValue()),
            [
                ...$tableDiff->getAddedColumns(),
                ...array_map(static fn (ColumnDiff $diff): Column => $diff->getNewColumn(), $tableDiff->getChangedColumns()),
            ],
        );

        $modifications = [];
        foreach (SchemaHelper::getCompressibleColumns() as $columnName) {
            if (!$expectedTable->hasColumn($columnName) || \in_array($columnName, $alteredColumns, true)) {
                continue;
            }

            if ($isCompressionEnabled === isset($compressedColumns[$columnName])) {
                continue;
            }

            $column = $expectedTable->getColumn($columnName);
            $modifications[] = 'MODIFY '.$column->getObjectName()->toSQL($platform).' '
                .SchemaHelper::getCompressibleColumnDeclarationSQL($column, $connection, $isCompressionEnabled);
        }

        if ([] === $modifications) {
            return null;
        }

        return 'ALTER TABLE '.$expectedTable->getObjectName()->toSQL($platform).' '.implode(', ', $modifications);
    }

    /**
     * Returns the compressed columns of the tables of a schema (MariaDB only).
     *
     * @return array<string, array<string, true>> lowercased column names, by lowercased table name
     */
    private function getCompressedColumns(Connection $connection, string $schemaName): array
    {
        $rows = $connection->fetchAllNumeric(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_TYPE LIKE '%COMPRESSED%'",
            [$schemaName],
        );

        $compressed = [];
        foreach ($rows as [$table, $column]) {
            $compressed[mb_strtolower((string) $table)][mb_strtolower((string) $column)] = true;
        }

        return $compressed;
    }

    /**
     * Returns the type of an audit table column: JSON columns fall back to TEXT when the platform does not
     * support JSON.
     */
    private function resolveColumnType(string $type, bool $isJsonSupported): string
    {
        return !$isJsonSupported && \in_array($type, DoctrineHelper::jsonStringTypes(), true) ? Types::TEXT : $type;
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
        return $this->buildTable(
            $auditTablename,
            SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
            SchemaHelper::getAuditTableIndices($auditTablename),
            $connection,
            $schemaConfig,
        );
    }

    /**
     * Builds the audit root index table, detached from any schema.
     *
     * @throws Exception
     */
    private function buildRootIndexTable(Connection $connection, SchemaConfig $schemaConfig): Table
    {
        $tableName = $this->rootIndexTableName();

        return $this->buildTable(
            $tableName,
            SchemaHelper::getRootIndexTableColumns(PlatformHelper::getColumnPlatformOptions($connection)),
            SchemaHelper::getRootIndexTableIndices($tableName),
            $connection,
            $schemaConfig,
        );
    }

    /**
     * @param array<string, array{type: string, options: array<string, mixed>}>                   $columns
     * @param array<string, array{type: string, name?: string, columns?: non-empty-list<string>}> $indices
     *
     * @throws Exception
     */
    private function buildTable(string $tablename, array $columns, array $indices, Connection $connection, SchemaConfig $schemaConfig): Table
    {
        $editor = Table::editor()
            ->setName(Parsers::parseOptionallyQualifiedName($tablename))
            // Schema::createTable() used to apply the default table options of the schema configuration
            ->setOptions($schemaConfig->getDefaultTableOptions())
            ->setConfiguration($schemaConfig->toTableConfiguration())
        ;

        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        $isCompressionEnabled = $this->isCompressionEnabled($connection);
        foreach ($columns as $columnName => $struct) {
            $editor->addColumn($this->compressColumn(
                SchemaHelper::buildColumn($columnName, $this->resolveColumnType($struct['type'], $isJsonSupported), $struct['options']),
                $connection,
                $isCompressionEnabled,
            ));
        }

        $this->addAuditTableIndices($editor, null, $indices, $connection);

        return $editor->create();
    }

    /**
     * Brings an existing audit table to the given structure: returns a new table and leaves the given one
     * untouched.
     *
     * @param array<string, array{type: string, options: array<string, mixed>}>                   $expectedColumns
     * @param array<string, array{type: string, name?: string, columns?: non-empty-list<string>}> $expectedIndices
     * @param list<string>                                                                        $obsoleteIndexNames
     *
     * @throws SchemaException
     */
    private function applyAuditTableStructure(Table $table, array $expectedColumns, array $expectedIndices, Connection $connection, array $obsoleteIndexNames = []): Table
    {
        $editor = $table->edit();
        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        $isCompressionEnabled = $this->isCompressionEnabled($connection);

        $processed = [];
        foreach ($table->getColumns() as $column) {
            $columnName = $column->getObjectName()->getIdentifier()->getValue();
            $editor->dropColumn($column->getObjectName());

            // column is part of expected columns: re-add it with its expected definition,
            // otherwise it has to be removed
            if (\array_key_exists($columnName, $expectedColumns)) {
                $editor->addColumn($this->compressColumn(SchemaHelper::buildColumn(
                    $columnName,
                    $this->resolveColumnType($expectedColumns[$columnName]['type'], $isJsonSupported),
                    $expectedColumns[$columnName]['options'],
                    $column,
                ), $connection, $isCompressionEnabled));
            }

            $processed[] = $columnName;
        }

        foreach ($expectedColumns as $columnName => $struct) {
            if (!\in_array($columnName, $processed, true)) {
                $editor->addColumn($this->compressColumn(
                    SchemaHelper::buildColumn($columnName, $struct['type'], $struct['options']),
                    $connection,
                    $isCompressionEnabled,
                ));
            }
        }

        $this->addAuditTableIndices($editor, $table, $expectedIndices, $connection, $obsoleteIndexNames);

        return $editor->create();
    }

    /**
     * Adds the primary key and indices of an audit table, replacing the ones of $currentTable.
     *
     * @param array<string, array{type: string, name?: string, columns?: non-empty-list<string>}> $expectedIndices
     * @param list<string>                                                                        $obsoleteIndexNames indices to drop if $currentTable has them
     */
    private function addAuditTableIndices(TableEditor $editor, ?Table $currentTable, array $expectedIndices, Connection $connection, array $obsoleteIndexNames = []): void
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
                    $currentIndex = $currentTable->getIndex($struct['name']);

                    // Skip drop+recreate when the index already exists with the same columns — avoids
                    // generating DROP INDEX SQL (MySQL requires ON <table> which DBAL omits on ALTER).
                    $existingColumns = array_map(
                        static fn (IndexedColumn $col): string => $col->getColumnName()->toString(),
                        $currentIndex->getIndexedColumns(),
                    );
                    if ($existingColumns === $columns) {
                        continue;
                    }

                    $editor->dropIndex($currentIndex->getObjectName());
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

        // Indices of earlier versions that only slow the audit INSERTs down
        foreach ($obsoleteIndexNames as $indexName) {
            if ($currentTable instanceof Table && $currentTable->hasIndex($indexName)) {
                $editor->dropIndex($currentTable->getIndex($indexName)->getObjectName());
            }
        }
    }

    /**
     * Adds the columns, primary key and indices of an audit table with the mutable schema API.
     *
     * Only used for schemas of an ORM that does not use the schema editor API, see addAuditTableToSchema().
     */
    private function populateLegacyAuditTable(Table $auditTable, string $auditTablename, Connection $connection, bool $isRootIndex = false): void
    {
        // Add columns to audit table
        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        $isCompressionEnabled = $this->isCompressionEnabled($connection);
        $platformOptions = PlatformHelper::getColumnPlatformOptions($connection);
        $columns = $isRootIndex ? SchemaHelper::getRootIndexTableColumns($platformOptions) : SchemaHelper::getAuditTableColumns($platformOptions);
        $indices = $isRootIndex ? SchemaHelper::getRootIndexTableIndices($auditTablename) : SchemaHelper::getAuditTableIndices($auditTablename);
        foreach ($columns as $columnName => $struct) {
            $type = $this->resolveColumnType($struct['type'], $isJsonSupported);
            $options = $struct['options'];
            $columnDefinition = $this->compressColumn(SchemaHelper::buildColumn($columnName, $type, $options), $connection, $isCompressionEnabled)->getColumnDefinition();
            if (null !== $columnDefinition) {
                $options['columnDefinition'] = $columnDefinition;
            }

            // @phpstan-ignore method.deprecated (mutable schema of an ORM not using the schema editor API)
            $auditTable->addColumn($columnName, $type, $options);
        }

        // Add indices to audit table
        foreach ($indices as $columnName => $struct) {
            \assert(\is_string($columnName));
            if ('primary' === $struct['type']) {
                DoctrineHelper::setPrimaryKey($auditTable, $columnName);
            } elseif (isset($struct['name'])) {
                // Composite indexes carry an explicit 'columns' list; single-column indexes derive
                // from the array key (which is the column name for non-composite entries).
                $columns = $struct['columns'] ?? [$columnName];
                // @phpstan-ignore method.deprecated (mutable schema of an ORM not using the schema editor API)
                $auditTable->addIndex(
                    $columns,
                    $struct['name'],
                    [],
                    // Length limiting only applies to single string columns, not composites
                    1 === \count($columns) && PlatformHelper::isIndexLengthLimited($columns[0], $connection)
                        ? ['lengths' => [191]]
                        : []
                );
            } else {
                throw new InvalidArgumentException(\sprintf("Missing key 'name' for column '%s'", $columnName));
            }
        }
    }
}
