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
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

/**
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
            $storageConnection = $storageServices[$name]->getEntityManager()->getConnection();
            $storageSchemaManager = $storageConnection->createSchemaManager();

            $storageSchema = $storageSchemaManager->introspectSchema();
            $fromSchema = clone $storageSchema;

            $processed = [];
            foreach ($classes as $entityFQCN => $tableName) {
                if (!\in_array($entityFQCN, $processed, true)) {
                    /** @var string $auditTablename */
                    $auditTablename = $this->resolveAuditTableName($entityFQCN, $configuration);

                    if ($storageSchema->hasTable($auditTablename)) {
                        // Audit table exists, let's update it if needed
                        $this->updateAuditTable($entityFQCN, $storageSchema);
                    } else {
                        // Audit table does not exists, let's create it
                        $this->createAuditTable($entityFQCN, $storageSchema);
                    }

                    $processed[] = $entityFQCN;
                }
            }

            $platform = $storageConnection->getDatabasePlatform();
            $sqls[$name] = $platform->getAlterSchemaSQL(
                new Comparator($platform)->compareSchemas($fromSchema, $storageSchema)
            );
        }

        return $sqls;
    }

    /**
     * Creates an audit table.
     *
     * @throws Exception
     */
    public function createAuditTable(string $entity, ?Schema $schema = null): Schema
    {
        /** @var StorageService $storageService */
        $storageService = $this->provider->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();

        if (!$schema instanceof Schema) {
            $schema = $connection->createSchemaManager()->introspectSchema();
        }

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $auditTablename = $this->resolveAuditTableName($entity, $configuration);

        if (null !== $auditTablename && !$schema->hasTable($auditTablename)) {
            $auditTable = $schema->createTable($auditTablename);

            // Add columns to audit table
            $isJsonSupported = PlatformHelper::isJsonSupported($connection);
            foreach (SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)) as $columnName => $struct) {
                if (\in_array($struct['type'], DoctrineHelper::jsonStringTypes(), true)) {
                    $type = $isJsonSupported ? Types::JSON : Types::TEXT;
                } else {
                    $type = $struct['type'];
                }

                $auditTable->addColumn($columnName, $type, $struct['options']);
            }

            // Add indices to audit table
            foreach (SchemaHelper::getAuditTableIndices($auditTablename) as $columnName => $struct) {
                \assert(\is_string($columnName));
                if ('primary' === $struct['type']) {
                    DoctrineHelper::setPrimaryKey($auditTable, $columnName);
                } elseif (isset($struct['name'])) {
                    // Composite indexes carry an explicit 'columns' list; single-column indexes derive
                    // from the array key (which is the column name for non-composite entries).
                    $columns = $struct['columns'] ?? [$columnName];
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

        return $schema;
    }

    /**
     * Ensures an audit table's structure is valid.
     *
     * @throws SchemaException
     * @throws Exception
     */
    public function updateAuditTable(string $entity, ?Schema $schema = null): Schema
    {
        /** @var StorageService $storageService */
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

        // process columns
        $this->processColumns($table, $table->getColumns(), SchemaHelper::getAuditTableColumns(PlatformHelper::getColumnPlatformOptions($connection)), $connection);

        // process indices
        $this->processIndices($table, SchemaHelper::getAuditTableIndices($auditTablename), $connection);

        return $schema;
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

    private function processColumns(Table $table, array $columns, array $expectedColumns, Connection $connection): void
    {
        $processed = [];

        $isJsonSupported = PlatformHelper::isJsonSupported($connection);
        foreach ($columns as $column) {
            if (\array_key_exists($column->getName(), $expectedColumns)) {
                // column is part of expected columns
                $table->dropColumn($column->getName());

                if (\in_array($expectedColumns[$column->getName()]['type'], DoctrineHelper::jsonStringTypes(), true) && !$isJsonSupported) {
                    $type = Types::TEXT;
                } else {
                    $type = $expectedColumns[$column->getName()]['type'];
                }

                $columnOptions = $expectedColumns[$column->getName()]['options'];

                // Preserve the column's existing platformOptions (e.g. MySQL charset/collation)
                // when the expected definition does not configure any. Without this, re-adding
                // the column with platformOptions: [] produces a schema that differs from the
                // introspected one, causing a false-positive ALTER TABLE on every run.
                // @see https://github.com/DamienHarper/auditor/issues/276
                if (isset($columnOptions['platformOptions']) && [] === $columnOptions['platformOptions']) {
                    $columnOptions['platformOptions'] = $column->getPlatformOptions();
                }

                $table->addColumn($column->getName(), $type, $columnOptions);
            } else {
                // column is not part of expected columns so it has to be removed
                $table->dropColumn($column->getName());
            }

            $processed[] = $column->getName();
        }

        foreach ($expectedColumns as $columnName => $options) {
            if (!\in_array($columnName, $processed, true)) {
                $table->addColumn($columnName, $options['type'], $options['options']);
            }
        }
    }

    /**
     * @throws SchemaException
     */
    private function processIndices(Table $table, array $expectedIndices, Connection $connection): void
    {
        foreach ($expectedIndices as $columnName => $options) {
            \assert(\is_string($columnName));
            if ('primary' === $options['type']) {
                $table->dropPrimaryKey();
                DoctrineHelper::setPrimaryKey($table, $columnName);
            } else {
                // Composite indexes carry an explicit 'columns' list; single-column indexes derive
                // from the array key (which is the column name for non-composite entries).
                $columns = $options['columns'] ?? [$columnName];

                // Skip drop+recreate when the index already exists with the same columns — avoids
                // generating DROP INDEX SQL (MySQL requires ON <table> which DBAL omits on ALTER).
                if ($table->hasIndex($options['name'])) {
                    $existingColumns = array_map(
                        static fn (IndexedColumn $col): string => $col->getColumnName()->toString(),
                        $table->getIndex($options['name'])->getIndexedColumns(),
                    );
                    if ($existingColumns === $columns) {
                        continue;
                    }

                    $table->dropIndex($options['name']);
                }

                $table->addIndex(
                    $columns,
                    $options['name'],
                    [],
                    // Length limiting only applies to single string columns, not composites
                    1 === \count($columns) && PlatformHelper::isIndexLengthLimited($columns[0], $connection)
                        ? ['lengths' => [191]]
                        : []
                );
            }
        }
    }
}
