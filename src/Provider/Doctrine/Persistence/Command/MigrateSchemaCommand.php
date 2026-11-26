<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\MigrateSchemaCommandTest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Mapping\FieldMapping;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

/**
 * Migrates audit table schemas from v1 (legacy) to v2 (unified format).
 *
 * Schema v1 has: transaction_hash, blame_user, blame_user_fqdn, blame_user_firewall, ip
 * Schema v2 has: transaction_id (CHAR 26), blame (JSON), schema_version
 *
 * Migration is split into two DDL phases so that optional data conversions can run
 * while legacy columns are still present:
 *
 *   Phase 1 (additive)   — add new columns, mark rows as schema_version=1, migrate blame JSON
 *   Conversions          — --convert-transaction-hash and/or --convert-diffs (reads transaction_hash)
 *   Phase 2 (destructive)— drop the indexes covering legacy columns, then the legacy columns (transaction_hash, blame_user, …)
 *
 * Phase 2 is skipped when --limit is set so that partial runs can be resumed safely.
 *
 * @see MigrateSchemaCommandTest
 */
#[AsCommand(
    name: 'audit:schema:migrate',
    description: 'Migrate audit table schema to the latest version',
)]
final class MigrateSchemaCommand extends Command
{
    use LockableTrait;

    /**
     * Old columns present in schema v1 that are removed in v2.
     */
    private const array LEGACY_COLUMNS = ['transaction_hash', 'blame_user', 'blame_user_fqdn', 'blame_user_firewall', 'ip'];

    /**
     * New columns introduced in schema v2.
     */
    private const array NEW_COLUMNS = ['schema_version', 'transaction_id', 'blame'];

    /**
     * Batch size for converting diffs rows (--convert-diffs).
     */
    private const int BATCH_SIZE = 500;

    /**
     * Batch size for converting transaction hashes (--convert-transaction-hash): the UPDATE of a batch takes three
     * parameters per hash, below the 999 parameters of SQLite before 3.32.
     */
    private const int HASH_BATCH_SIZE = 300;

    private Auditor $auditor;

    public function setAuditor(Auditor $auditor): self
    {
        $this->auditor = $auditor;

        return $this;
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL statements without executing them.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Execute the migration against the database.')
            ->addOption('convert-diffs', null, InputOption::VALUE_NONE, 'Convert legacy diffs JSON to the new {source, changes} envelope (sets schema_version=2 on migrated rows).')
            ->addOption('convert-transaction-hash', null, InputOption::VALUE_NONE, 'Convert legacy transaction_hash (SHA1) values to transaction_id (ULID), preserving transactional grouping across all audit tables.')
            ->addOption('convert-all', null, InputOption::VALUE_NONE, 'Shorthand for --convert-diffs --convert-transaction-hash (recommended).')
            ->addOption('drop-transaction-hash', null, InputOption::VALUE_NONE, 'Drop transaction_hash WITHOUT converting it: the legacy rows lose their transaction grouping for good.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit conversions to N unique transaction hashes (--convert-transaction-hash) or N total rows (--convert-diffs). Useful for estimating migration time. When set, legacy columns are NOT dropped so the run can be resumed.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            // a non-zero exit code: a deployment script must not go on as if the tables had been migrated
            $io->error('The command is already running in another process.');

            return Command::FAILURE;
        }

        try {
            return $this->migrate($input, $io);
        } finally {
            $this->release();
        }
    }

    private function migrate(InputInterface $input, SymfonyStyle $io): int
    {
        $dumpSql = true === $input->getOption('dump-sql');
        $force = true === $input->getOption('force');
        $convertAll = true === $input->getOption('convert-all');
        $convertDiffs = $convertAll || true === $input->getOption('convert-diffs');
        $convertTransactionHash = $convertAll || true === $input->getOption('convert-transaction-hash');
        $dropTransactionHashOption = true === $input->getOption('drop-transaction-hash');

        $limitRaw = $input->getOption('limit');
        $limit = null;
        if (null !== $limitRaw) {
            if (!\is_string($limitRaw) || 1 !== preg_match('/^[1-9]\d*$/', $limitRaw)) {
                $io->error('--limit must be a positive integer.');

                return Command::FAILURE;
            }

            $limit = (int) $limitRaw;
        }

        if ($convertTransactionHash && $dropTransactionHashOption) {
            $io->error('--drop-transaction-hash drops transaction_hash without converting it: it cannot be combined with --convert-transaction-hash or --convert-all.');

            return Command::FAILURE;
        }

        if (null !== $limit && (!$convertDiffs && !$convertTransactionHash || $dropTransactionHashOption)) {
            // --limit only limits conversions, and keeps the legacy columns: it would silently ignore the drop
            $io->error('--limit limits the conversions (--convert-diffs, --convert-transaction-hash, --convert-all): it needs one of them, and cannot be combined with --drop-transaction-hash.');

            return Command::FAILURE;
        }

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);
        $provider->getConfiguration();

        $schemaManager = new SchemaManager($provider);
        $tables = $this->collectLegacyTables($provider, $schemaManager);

        // Tables whose legacy columns are gone but that still hold unconverted diffs (schema_version = 1): left by
        // a run without --convert-diffs, or by an interrupted one. They are converted along with the legacy tables.
        $diffsOnlyTables = $this->collectDiffsConversionTables($provider, $schemaManager, $tables);
        $legacyDiffsRows = $this->countLegacyDiffsRows($diffsOnlyTables);

        if ([] === $tables && [] === $diffsOnlyTables) {
            $io->success('All audit tables are already at schema version 2. Nothing to migrate.');

            return Command::SUCCESS;
        }

        if ([] !== $tables) {
            $io->text(\sprintf('Found <info>%d</info> audit table(s) to migrate:', \count($tables)));
            foreach (array_keys($tables) as $tableName) {
                $io->text(\sprintf('  - %s', $tableName));
            }

            $io->newLine();
        }

        if ([] !== $diffsOnlyTables) {
            $io->text(\sprintf('Found <info>%d</info> row(s) with unconverted diffs (schema_version = 1) in:', $legacyDiffsRows));
            foreach (array_keys($diffsOnlyTables) as $tableName) {
                $io->text(\sprintf('  - %s', $tableName));
            }

            $io->newLine();
        }

        // transaction_hash is dropped once converted, or when its loss is explicitly accepted (--drop-transaction-hash).
        // Otherwise it is kept, and the table stays a legacy table until it is converted.
        $dropTransactionHash = $convertTransactionHash || $dropTransactionHashOption;

        $allDdlSqls = $this->generateDdlStatements($tables, $dropTransactionHash);

        // Tables to use for diffs conversion: DDL tables + diffs-only tables (already migrated DDL).
        $allDiffsTables = array_merge($tables, $diffsOnlyTables);

        $hasDdl = [] !== array_filter(array_column($allDdlSqls, 'additive')) || [] !== array_filter(array_column($allDdlSqls, 'destructive'));

        if ([] === $tables && !$convertDiffs) {
            // Only unconverted diffs are left: this is not "nothing to migrate".
            $io->caution([
                \sprintf('%d row(s) still have legacy diffs (schema_version = 1).', $legacyDiffsRows),
                '',
                \sprintf('Run <info>%s --force --convert-diffs</info> to convert them.', (string) $this->getName()),
            ]);

            return Command::FAILURE;
        }

        if (!$hasDdl && !$convertDiffs && !$convertTransactionHash) {
            // The legacy tables only keep transaction_hash (kept by a run without --convert-transaction-hash), and
            // maybe unconverted diffs: nothing to do without a conversion option, but the migration is not complete.
            $io->caution([
                \sprintf('%d audit table(s) still have transaction_hash, and %d row(s) legacy diffs (schema_version = 1).', \count($tables), $this->countLegacyDiffsRows($allDiffsTables)),
                '',
                \sprintf('Run <info>%s --force --convert-all</info> to convert them, or <info>--force --drop-transaction-hash</info> to drop transaction_hash without converting it.', (string) $this->getName()),
            ]);

            return Command::FAILURE;
        }

        if ($dumpSql) {
            $io->text('The following SQL statements will be executed:');
            $io->newLine();
            foreach ($allDdlSqls as $phases) {
                foreach ($phases['additive'] as $sql) {
                    $io->text(\sprintf('    %s;', $sql));
                }
            }

            if ($convertTransactionHash) {
                $io->text('    [+ UPDATE queries to convert transaction_hash → transaction_id (ULID, cross-table)]');
            }

            if ($convertDiffs) {
                $io->text('    [+ UPDATE queries to convert diffs (executed in PHP batches)]');
            }

            if (null === $limit) {
                foreach ($allDdlSqls as $phases) {
                    foreach ($phases['destructive'] as $sql) {
                        $io->text(\sprintf('    %s;', $sql));
                    }
                }
            } else {
                $io->text('    [legacy columns are NOT dropped when --limit is set]');
            }

            $io->newLine();
        }

        if (!$force) {
            if (!$dumpSql) {
                $commandName = (string) $this->getName();
                $io->caution([
                    \sprintf('Found <info>%d</info> audit table(s) requiring migration.', \count($tables) + \count($diffsOnlyTables)),
                    '',
                    'Back up the audit tables first: the legacy columns are dropped once converted. Then run:',
                    '',
                    \sprintf('    <info>%s --force --convert-all</info>                       migrate everything (recommended)', $commandName),
                    \sprintf('    <info>%s --dump-sql --convert-all</info>                    dump the SQL statements', $commandName),
                    \sprintf('    <info>%s --force --convert-all --limit=1000</info>          convert a sample on a copy and estimate the duration', $commandName),
                    \sprintf('    <info>%s --force --convert-transaction-hash</info>          convert the transaction hashes only, the diffs later', $commandName),
                    \sprintf('    <info>%s --force --convert-diffs</info>                     convert the diffs only, the transaction hashes later', $commandName),
                    '',
                    \sprintf('<info>%s --force --drop-transaction-hash</info> drops transaction_hash WITHOUT converting it:', $commandName),
                    'the legacy rows lose their transaction grouping for good.',
                ]);
            }

            return $dumpSql ? Command::SUCCESS : Command::FAILURE;
        }

        // Phase 1: additive DDL — add new columns, mark rows, migrate blame
        foreach ($tables as $tableName => $connection) {
            $additiveSqls = $allDdlSqls[$tableName]['additive'] ?? [];
            if ([] !== $additiveSqls) {
                $io->text(\sprintf('  Applying additive schema changes for <info>%s</info>...', $tableName));
                $this->executeStatements($connection, $additiveSqls);
            }
        }

        // Cross-table conversion: transaction_hash → transaction_id
        // Must run BEFORE Phase 2 which drops transaction_hash.
        if ($convertTransactionHash) {
            $io->newLine();
            $io->text('<comment>Converting transaction_hash → transaction_id (ULID)...</comment>');
            $this->convertTransactionHashAcrossTables($tables, $limit, $io);
        }

        // Per-table diffs conversion (includes tables whose DDL was already applied in a previous run)
        $unconvertible = [];
        if ($convertDiffs) {
            $io->newLine();
            $io->text('<comment>Converting legacy diffs...</comment>');
            $unconvertible = $this->convertDiffsAcrossTables(
                $allDiffsTables,
                $this->collectJsonFields($provider, $schemaManager),
                $this->collectAuditedEntities($provider, $schemaManager),
                $limit,
                $io,
            );
        }

        // Phase 2: destructive DDL — drop legacy columns
        // Skipped when --limit is set so partial runs can be safely resumed.
        if (null === $limit) {
            foreach ($tables as $tableName => $connection) {
                $destructiveSqls = $allDdlSqls[$tableName]['destructive'] ?? [];
                if ([] !== $destructiveSqls) {
                    $io->text(\sprintf('  Dropping legacy columns for <info>%s</info>...', $tableName));
                    $this->executeStatements($connection, $destructiveSqls);
                }
            }
        }

        $io->newLine();

        // what is left to do once this run is over
        $legacyTablesLeft = $this->collectLegacyTables($provider, $schemaManager);
        $legacyDiffsRowsLeft = $this->countLegacyDiffsRows(array_merge($legacyTablesLeft, $this->collectDiffsConversionTables($provider, $schemaManager, $legacyTablesLeft)));

        if ([] !== $unconvertible) {
            $lines = [
                \sprintf('%d row(s) could not be converted and were left as they are (schema_version = 1):', array_sum(array_map(count(...), $unconvertible))),
                'their diffs are not valid JSON, do not decode to an object, hold a field entry that is not an object, or their type is unknown.',
                '',
            ];
            foreach ($unconvertible as $tableName => $ids) {
                $lines[] = \sprintf('  - %s, id %s%s', $tableName, implode(', ', \array_slice($ids, 0, 20)), \count($ids) > 20 ? ', …' : '');
            }

            $lines[] = '';
            $lines[] = 'They stay readable as legacy entries. Fix or delete them, then run the command again.';
            $io->warning($lines);
        } elseif ([] === $legacyTablesLeft && 0 === $legacyDiffsRowsLeft) {
            $io->success('Audit schema migration completed successfully!');
        } else {
            // a step of a migration run in several commands: done, but the migration is not complete
            $io->info(\sprintf(
                'Step done. The migration is not complete: %d audit table(s) keep legacy columns, %d row(s) legacy diffs (schema_version = 1).',
                \count($legacyTablesLeft),
                $legacyDiffsRowsLeft,
            ));
        }

        if (null !== $limit) {
            $io->note([
                'The conversions were limited by --limit: the legacy columns were NOT dropped.',
                \sprintf('Run %s --force --convert-all (without --limit) to complete the migration.', (string) $this->getName()),
            ]);
        } else {
            $remainingRows = $this->countLegacyDiffsRows($allDiffsTables);
            if (0 < $remainingRows && !$convertDiffs) {
                $io->note(\sprintf('%d row(s) keep their legacy diffs (schema_version = 1). Run with --convert-diffs to convert them.', $remainingRows));
            } elseif (0 === $remainingRows && $convertDiffs) {
                $io->note('Diffs conversion complete. All rows now have schema_version = 2.');
            }

            if ([] !== $tables && !$convertTransactionHash && $dropTransactionHashOption) {
                $io->note('transaction_hash has been dropped without conversion (--drop-transaction-hash): the legacy rows lost their transaction grouping.');
            } elseif ([] !== $tables && !$dropTransactionHash) {
                $io->note(\sprintf('transaction_hash has been kept. Run %s --force --convert-transaction-hash to convert it and finish the migration.', (string) $this->getName()));
            }
        }

        return [] === $unconvertible ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Runs statements of one audit table. On the platforms whose DDL is transactional (PostgreSQL, SQLite), they run
     * in one transaction: SQLite rebuilds a table to drop a column (copy, drop, create, copy back), and an
     * interruption in between would otherwise lose the table. MySQL and MariaDB commit each DDL statement anyway;
     * the statements are ordered so that a run interrupted between two of them is completed by the next one.
     *
     * @param list<string> $sqls
     */
    private function executeStatements(Connection $connection, array $sqls): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            $connection->transactional(static function (Connection $connection) use ($sqls): void {
                foreach ($sqls as $sql) {
                    $connection->executeStatement($sql);
                }
            });

            return;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /**
     * Returns the audit tables that still have a legacy column, on the storage of their entity (resolved by the
     * storage mapper): fully v1 tables, and tables left partially migrated by an interrupted run or by
     * a run that kept transaction_hash.
     *
     * @return array<string, Connection> keyed by table name
     */
    private function collectLegacyTables(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        $legacyTables = [];
        foreach ($this->collectAuditTables($provider, $schemaManager) as $auditTableName => [$connection, $table]) {
            if ([] !== array_filter(self::LEGACY_COLUMNS, $table->hasColumn(...))) {
                $legacyTables[$auditTableName] = $connection;
            }
        }

        return $legacyTables;
    }

    /**
     * Returns the existing audit tables of the configured entities, each on the storage its entity is mapped to.
     *
     * @return array<string, array{Connection, Table}> keyed by table name
     */
    private function collectAuditTables(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        /** @var Configuration $configuration */
        $configuration = $provider->getConfiguration();
        $schemas = [];
        $auditTables = [];

        foreach (array_keys($configuration->getEntities()) as $entity) {
            $auditTableName = $schemaManager->resolveAuditTableName($entity, $configuration);
            if (null === $auditTableName) {
                continue;
            }

            $connection = $provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
            $schema = $schemas[spl_object_id($connection)] ??= $connection->createSchemaManager()->introspectSchema();
            if (!$schema->hasTable($auditTableName)) {
                continue;
            }

            if (isset($auditTables[$auditTableName]) && $auditTables[$auditTableName][0] !== $connection) {
                // the tables are keyed by name: two of them would be migrated as one
                throw new \RuntimeException(\sprintf('The audit table %s exists on several storages: audit:schema:migrate does not support it, as it would convert them as one table. Give the audited tables distinct names (table_prefix or table_suffix are global, so rename one of the entity tables) before migrating.', $auditTableName));
            }

            $auditTables[$auditTableName] = [$connection, $schema->getTable($auditTableName)];
        }

        return $auditTables;
    }

    /**
     * Returns, per audit table, the table of the audited entities and the classes stored in it.
     * Several classes share an audit table under single table inheritance.
     *
     * @return array<string, array{table: string, classes: list<string>}> keyed by audit table name
     */
    private function collectAuditedEntities(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        /** @var Configuration $configuration */
        $configuration = $provider->getConfiguration();
        $auditedEntities = [];

        foreach ($configuration->getEntities() as $entity => $options) {
            $auditTableName = $schemaManager->resolveAuditTableName($entity, $configuration);
            if (null === $auditTableName) {
                continue;
            }

            $auditedEntities[$auditTableName]['table'] = $options['table_name'];
            $auditedEntities[$auditTableName]['classes'][] = $entity;
        }

        return $auditedEntities;
    }

    /**
     * Counts the rows with unconverted diffs (schema_version = 1) of the given tables.
     *
     * @param array<string, Connection> $tables
     */
    private function countLegacyDiffsRows(array $tables): int
    {
        $rows = 0;
        foreach ($tables as $tableName => $connection) {
            if (!\in_array('schema_version', SchemaHelper::getTableColumnNames($connection, $tableName), true)) {
                continue;
            }

            $count = $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE schema_version = 1', $tableName));
            $rows += is_numeric($count) ? (int) $count : 0;
        }

        return $rows;
    }

    /**
     * Returns the audit tables that no longer have any legacy column but still hold rows with unconverted diffs
     * (schema_version = 1): the diffs are converted independently of the legacy columns, in the same run as the
     * legacy tables or in a later one.
     *
     * @param array<string, Connection> $legacyTables
     *
     * @return array<string, Connection> keyed by table name
     */
    private function collectDiffsConversionTables(DoctrineProvider $provider, SchemaManager $schemaManager, array $legacyTables): array
    {
        $tables = [];
        foreach ($this->collectAuditTables($provider, $schemaManager) as $auditTableName => [$connection, $table]) {
            if (isset($legacyTables[$auditTableName]) || !$table->hasColumn('schema_version')) {
                continue;
            }

            if (0 < $this->countLegacyDiffsRows([$auditTableName => $connection])) {
                $tables[$auditTableName] = $connection;
            }
        }

        return $tables;
    }

    /**
     * Generates additive and destructive DDL SQL statements per legacy audit table.
     *
     * Additive: add new columns (idempotent — skips already-present columns),
     *           existing rows get schema_version=1, migrate blame columns to JSON.
     * Destructive: drop the indexes covering legacy columns, then the legacy columns (transaction_hash, blame_user, …),
     *              in one table alteration: SQLite rebuilds the table once instead of once per column and index.
     *
     * @param array<string, Connection> $tables
     * @param bool                      $dropTransactionHash Whether to include transaction_hash in the destructive phase:
     *                                                       false keeps it, unconverted, for a later run
     *
     * @return array<string, array{additive: list<string>, destructive: list<string>}>
     */
    private function generateDdlStatements(array $tables, bool $dropTransactionHash): array
    {
        $allSqls = [];

        foreach ($tables as $tableName => $connection) {
            $additive = [];
            $destructive = [];
            $platform = $connection->getDatabasePlatform();
            $dbSchemaManager = $connection->createSchemaManager();
            // Only the audit table is compared: the other tables of the database are left out.
            $comparator = $dbSchemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));
            $table = SchemaHelper::normalizeJsonbColumns($dbSchemaManager->introspectSchema()->getTable($tableName));

            // Applies a modification to the audit table and returns the matching SQL statements.
            $alter = static function (callable $modification) use (&$table, $comparator, $platform): array {
                $editor = $table->edit();
                $modification($editor);
                $newTable = $editor->create();
                $diff = $comparator->compareTables($table, $newTable);
                $table = $newTable;

                return $diff->isEmpty() ? [] : $platform->getAlterTableSQL($diff);
            };

            // 1. Add new columns (only those not yet present)
            $newColumns = SchemaHelper::getAuditTableColumns();
            foreach (self::NEW_COLUMNS as $colName) {
                if (!$table->hasColumn($colName)) {
                    $struct = $newColumns[$colName];
                    $colType = $struct['type'];
                    $colOptions = $struct['options'];

                    if (\in_array($colType, ['json', 'json_array'], true)) {
                        $colType = 'text';
                    }

                    // Existing rows are legacy rows: adding the column with a default of 1 marks them in the
                    // same statement, so that a run interrupted right after it cannot leave them at 2. The
                    // alteration that drops the last legacy column sets the default of the v2 schema (step 3).
                    if ('schema_version' === $colName) {
                        $colOptions['default'] = 1;
                    }

                    $column = SchemaHelper::buildColumn($colName, $colType, $colOptions);
                    foreach ($alter(static fn (TableEditor $editor): TableEditor => $editor->addColumn($column)) as $sql) {
                        $additive[] = $sql;
                    }
                }
            }

            // 2. Migrate blame fields to the new blame JSON column (idempotent — WHERE filters nulls). The legacy columns
            // are dropped in one alteration, so they are either all there or all gone.
            if ($table->hasColumn('blame_user')) {
                $additive[] = $this->buildBlameMigrationSql($tableName, $connection);
            }

            // 3. Drop legacy columns (destructive — executed after conversions, and only when --limit is not set).
            // Skip transaction_hash if it should be preserved for a subsequent conversion run.
            $droppedColumns = array_values(array_filter(
                self::LEGACY_COLUMNS,
                static fn (string $colName): bool => $table->hasColumn($colName) && ($dropTransactionHash || 'transaction_hash' !== $colName),
            ));

            // Indexes covering a dropped column are dropped with it: the transaction_hash index of v1 tables,
            // the (blame_user, blame_id) index of provider 1.x, or any custom one. MySQL and MariaDB would
            // otherwise keep them on the remaining columns under their legacy name.
            // The transaction_hash index goes with its column, not in the additive phase:
            // --convert-transaction-hash looks rows up by transaction_hash.
            $droppedIndexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexedColumns = array_map(
                    static fn (IndexedColumn $column): string => mb_strtolower($column->getColumnName()->getIdentifier()->getValue()),
                    $index->getIndexedColumns(),
                );

                if ([] !== array_intersect($indexedColumns, $droppedColumns)) {
                    $droppedIndexes[] = $index->getObjectName();
                }
            }

            // Once the last legacy column is gone, no v1 row can be written any more: the same alteration sets the
            // default of the v2 schema. A table that keeps transaction_hash keeps the default of 1.
            $isLastLegacyAlteration = [] === array_diff(array_filter(self::LEGACY_COLUMNS, $table->hasColumn(...)), $droppedColumns);
            $schemaVersionDefault = SchemaHelper::getAuditTableColumns()['schema_version']['options']['default'];

            $droppedColumnNames = array_map(static fn (string $colName): UnqualifiedName => $table->getColumn($colName)->getObjectName(), $droppedColumns);
            if ([] !== $droppedColumnNames) {
                $destructive = $alter(static function (TableEditor $editor) use ($droppedIndexes, $droppedColumnNames, $isLastLegacyAlteration, $schemaVersionDefault): TableEditor {
                    foreach ($droppedIndexes as $indexName) {
                        $editor->dropIndex($indexName);
                    }

                    foreach ($droppedColumnNames as $columnName) {
                        $editor->dropColumn($columnName);
                    }

                    if ($isLastLegacyAlteration) {
                        $editor->modifyColumnByUnquotedName('schema_version', static function (ColumnEditor $column) use ($schemaVersionDefault): void {
                            $column->setDefaultValue($schemaVersionDefault);
                        });
                    }

                    return $editor;
                });
            }

            $allSqls[$tableName] = ['additive' => $additive, 'destructive' => $destructive];
        }

        return $allSqls;
    }

    /**
     * Builds the SQL statement that migrates legacy blame columns into the new blame JSON column.
     */
    private function buildBlameMigrationSql(string $tableName, Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();
        $jsonBuild = match (true) {
            $platform instanceof MySQLPlatform, $platform instanceof MariaDBPlatform => "JSON_OBJECT('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
            $platform instanceof PostgreSQLPlatform => "json_build_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)::jsonb",
            $platform instanceof SQLitePlatform => "json_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
            default => "json_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
        };

        return \sprintf(
            'UPDATE %s SET blame = %s WHERE blame_user IS NOT NULL OR blame_user_fqdn IS NOT NULL OR blame_user_firewall IS NOT NULL OR ip IS NOT NULL',
            $tableName,
            $jsonBuild,
        );
    }

    /**
     * Converts legacy transaction_hash (SHA1) values to transaction_id (ULID) across all tables.
     *
     * Table by table, the distinct hashes left to convert are read in batches of HASH_BATCH_SIZE, in hash order (keyset
     * pagination on the transaction_hash index). For each batch, the ULID a hash already got in any table (converted
     * earlier in this run, or by an interrupted run) is reused, and the other hashes get a new one: the entries of a
     * legacy transaction get one ULID across all tables and storages. One UPDATE per batch writes them.
     * Memory stays bounded by the batch size, and every table is only read and written for its own hashes.
     *
     * Idempotent: already-converted rows (transaction_id IS NOT NULL) are never touched.
     * --limit unit: number of unique hashes, counted per table.
     *
     * @param array<string, Connection> $tables
     */
    private function convertTransactionHashAcrossTables(array $tables, ?int $limit, SymfonyStyle $io): void
    {
        $tables = array_filter(
            $tables,
            static fn (Connection $connection, string $tableName): bool => \in_array('transaction_hash', SchemaHelper::getTableColumnNames($connection, $tableName), true),
            ARRAY_FILTER_USE_BOTH,
        );

        $leftHashes = 0;
        foreach ($tables as $tableName => $connection) {
            $count = $connection->fetchOne(\sprintf('SELECT COUNT(DISTINCT transaction_hash) FROM %s WHERE transaction_id IS NULL AND transaction_hash IS NOT NULL', $tableName));
            $leftHashes += is_numeric($count) ? (int) $count : 0;
        }

        if (0 === $leftHashes) {
            $io->text('  No transaction_hash values to convert.');

            return;
        }

        $startTime = microtime(true);
        $processed = 0;
        $totalRows = 0;

        foreach ($tables as $tableName => $connection) {
            $lastHash = '';
            $tableRows = 0;

            while (null === $limit || $processed < $limit) {
                $batchSize = null === $limit ? self::HASH_BATCH_SIZE : min(self::HASH_BATCH_SIZE, $limit - $processed);

                /** @var list<string> $hashes */
                $hashes = array_map(strval(...), $connection->fetchFirstColumn(
                    \sprintf('SELECT DISTINCT transaction_hash FROM %s WHERE transaction_hash > ? AND transaction_id IS NULL ORDER BY transaction_hash LIMIT %d', $tableName, $batchSize),
                    [$lastHash],
                ));

                if ([] === $hashes) {
                    break;
                }

                // the ULID each hash got in a table converted before, otherwise a new one
                $ulids = [];
                foreach ($tables as $otherTableName => $otherConnection) {
                    $pairs = $otherConnection->fetchAllNumeric(
                        \sprintf('SELECT DISTINCT transaction_hash, transaction_id FROM %s WHERE transaction_hash IN (?) AND transaction_id IS NOT NULL', $otherTableName),
                        [$hashes],
                        [ArrayParameterType::STRING],
                    );
                    foreach ($pairs as [$hash, $ulid]) {
                        $ulids[(string) $hash] ??= (string) $ulid;
                    }
                }

                $cases = [];
                $parameters = [];
                foreach ($hashes as $hash) {
                    $cases[] = 'WHEN ? THEN ?';
                    $parameters[] = $hash;
                    $parameters[] = $ulids[$hash] ?? (string) new Ulid();
                }

                $sql = \sprintf(
                    'UPDATE %s SET transaction_id = CASE transaction_hash %s END WHERE transaction_id IS NULL AND transaction_hash IN (%s)',
                    $tableName,
                    implode(' ', $cases),
                    implode(', ', array_fill(0, \count($hashes), '?')),
                );
                $tableRows += (int) $connection->executeStatement($sql, [...$parameters, ...$hashes]);

                $processed += \count($hashes);
                $lastHash = $hashes[\count($hashes) - 1];

                if (\count($hashes) < $batchSize) {
                    break;
                }
            }

            $totalRows += $tableRows;
            if (null !== $limit && $processed >= $limit) {
                break;
            }
        }

        $elapsed = microtime(true) - $startTime;

        $io->text(\sprintf(
            '  Converted <info>%d</info> / %d hash(es) → <info>%d</info> row(s) updated in %.1fs.',
            $processed,
            $leftHashes,
            $totalRows,
            $elapsed,
        ));

        if (null !== $limit && $processed < $leftHashes) {
            $remaining = $leftHashes - $processed;
            $estimatedSeconds = $processed > 0 ? (int) round($elapsed * $remaining / $processed) : 0;
            $io->text(\sprintf(
                '  → Estimated remaining: ~%s (%d hash(es) left)',
                $this->formatSeconds($estimatedSeconds),
                $remaining,
            ));
        }
    }

    /**
     * Lists, per audit table, the fields of the audited entities that are mapped to a JSON type.
     * Entities that share an audit table (single table inheritance) add up their fields: a field diff of another
     * class is never read as a deep diff anyway, its values are not a tree of {old, new} leaves (see isFieldDiff()).
     *
     * @return array<string, array<string, true>> field names as keys, keyed by audit table name
     */
    private function collectJsonFields(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        /** @var Configuration $configuration */
        $configuration = $provider->getConfiguration();
        $jsonFields = [];

        foreach (array_keys($configuration->getEntities()) as $entity) {
            $auditTableName = $schemaManager->resolveAuditTableName($entity, $configuration);
            if (null === $auditTableName) {
                continue;
            }

            try {
                $entityManager = $provider->getAuditingServiceForEntity($entity)->getEntityManager();
            } catch (\InvalidArgumentException) {
                // Not managed by any auditing entity manager: its JSON fields cannot be told apart.
                continue;
            }

            $connection = $entityManager->getConnection();
            $jsonTypes = DoctrineHelper::jsonTypes($connection);

            foreach ($entityManager->getClassMetadata($entity)->fieldMappings as $fieldName => $mapping) {
                $type = $mapping instanceof FieldMapping ? $mapping->type : $mapping['type'];
                if (\in_array(DoctrineHelper::getType($connection, $type), $jsonTypes, true)) {
                    $jsonFields[$auditTableName][$fieldName] = true;
                }
            }
        }

        return $jsonFields;
    }

    /**
     * Converts legacy diffs JSON across all tables, respecting an optional global row limit.
     *
     * @param array<string, Connection>                                  $tables
     * @param array<string, array<string, true>>                         $jsonFields      JSON fields per audit table (see collectJsonFields())
     * @param array<string, array{table: string, classes: list<string>}> $auditedEntities audited entities per audit table (see collectAuditedEntities())
     *
     * @return array<string, non-empty-list<int|string>> ids of the rows left unconverted, per table
     */
    private function convertDiffsAcrossTables(array $tables, array $jsonFields, array $auditedEntities, ?int $limit, SymfonyStyle $io): array
    {
        $totalRows = $this->countLegacyDiffsRows($tables);

        if (0 === $totalRows) {
            $io->text('  No legacy diffs rows to convert.');

            return [];
        }

        $startTime = microtime(true);
        $convertedTotal = 0;
        $remaining = $limit;
        $unconvertible = [];

        foreach ($tables as $tableName => $connection) {
            if (null !== $remaining && $remaining <= 0) {
                break;
            }

            $auditedEntity = $auditedEntities[$tableName] ?? ['table' => '', 'classes' => []];
            [$convertedInTable, $skipped] = $this->convertDiffsForTable($tableName, $connection, $jsonFields[$tableName] ?? [], $auditedEntity, $io, $remaining);
            $convertedTotal += $convertedInTable;
            if ([] !== $skipped) {
                $unconvertible[$tableName] = $skipped;
            }

            if (null !== $remaining) {
                $remaining -= $convertedInTable;
            }
        }

        $elapsed = microtime(true) - $startTime;

        $io->text(\sprintf(
            '  Converted <info>%d</info> / %d row(s) in %.1fs.',
            $convertedTotal,
            $totalRows,
            $elapsed,
        ));

        // the rows left unconverted are read again by every run, but never converted
        $leftRows = $totalRows - $convertedTotal - array_sum(array_map(count(...), $unconvertible));
        if (null !== $limit && 0 < $leftRows) {
            $estimatedSeconds = $convertedTotal > 0 ? (int) round($elapsed * $leftRows / $convertedTotal) : 0;
            $io->text(\sprintf(
                '  → Estimated remaining: ~%s (%d row(s) left)',
                $this->formatSeconds($estimatedSeconds),
                $leftRows,
            ));
        }

        return $unconvertible;
    }

    /**
     * Converts legacy diffs JSON rows for a single table.
     *
     * Processes rows with schema_version = 1 in batches of BATCH_SIZE, in id order.
     * Each batch starts after the last id of the previous one (keyset pagination): schema_version is not
     * indexed, so a query without this bound would read again every row already converted, and the
     * conversion of a large table would cost (rows / BATCH_SIZE) × rows row reads.
     * No OFFSET is used and every run starts from the first unconverted row, so the operation is
     * safe to resume after an interruption.
     * Each batch is written in one database transaction: in autocommit mode, each UPDATE would be committed
     * (and its log flushed to disk) on its own, which dominates the duration of the conversion. A failing row
     * rolls its whole batch back, and the next run converts it again.
     * A row that cannot be converted (see convertLegacyDiffs()) is left as it is, at schema_version 1, and reported.
     *
     * @param array<string, true>                         $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>} $auditedEntity table and classes audited in this table
     * @param ?int                                        $limit         Maximum number of rows to convert (null = no limit)
     *
     * @return array{int, list<int|string>} number of rows converted, ids of the rows left unconverted
     */
    private function convertDiffsForTable(string $tableName, Connection $connection, array $jsonFields, array $auditedEntity, SymfonyStyle $io, ?int $limit): array
    {
        $io->text(\sprintf('  Converting legacy diffs for <info>%s</info>...', $tableName));

        $converted = 0;
        $skipped = [];
        $lastId = 0;

        while (true) {
            $batchLimit = self::BATCH_SIZE;
            if (null !== $limit) {
                // --limit counts converted rows: the rows left unconverted do not use it up
                $remaining = $limit - $converted;
                if ($remaining <= 0) {
                    break;
                }

                $batchLimit = min(self::BATCH_SIZE, $remaining);
            }

            $rows = $connection->fetchAllAssociative(
                \sprintf(
                    'SELECT id, type, object_id, discriminator, diffs FROM %s WHERE schema_version = 1 AND id > ? ORDER BY id LIMIT %d',
                    $tableName,
                    $batchLimit,
                ),
                [$lastId],
                [ParameterType::INTEGER],
            );

            if ([] === $rows) {
                break;
            }

            $updates = [];
            foreach ($rows as $row) {
                $convertedDiffs = $this->convertLegacyDiffs($row, $jsonFields, $auditedEntity);
                if (null === $convertedDiffs) {
                    $skipped[] = $row['id'];

                    continue;
                }

                // escaped like the diffs written by the provider, so that the column charset does not matter
                $updates[] = ['id' => $row['id'], 'diffs' => json_encode($convertedDiffs, JSON_THROW_ON_ERROR)];
            }

            $connection->transactional(static function (Connection $connection) use ($tableName, $updates): void {
                foreach ($updates as $update) {
                    $connection->update($tableName, ['diffs' => $update['diffs'], 'schema_version' => 2], ['id' => $update['id']]);
                }
            });

            $converted += \count($updates);
            $lastId = $rows[\count($rows) - 1]['id'];

            if (\count($rows) < $batchLimit) {
                break;
            }
        }

        $io->text(\sprintf('    Converted <info>%d</info> row(s)%s.', $converted, [] === $skipped ? '' : \sprintf(', <comment>%d left unconverted</comment>', \count($skipped))));

        return [$converted, $skipped];
    }

    /**
     * Converts a single legacy diffs value to the new {source, changes, deep_diffs} envelope ('deep_diffs' only when not empty).
     *
     * Legacy formats per operation type:
     *  INSERT: {'@source': {id,class,label,table}, 'field': {'new': value}, ...}
     *  UPDATE: {'field': {'new': v, 'old': v}, ...} ('@source' was stripped before persisting)
     *  JSON fields (INSERT/UPDATE): nested deep diff, e.g. {'settings': {'theme': {'new': v, 'old': v}}}
     *  REMOVE: {'class': ..., 'id': ..., 'label': ..., 'table': ...}
     *  ASSOCIATE/DISSOCIATE: {'source': {...}, 'target': {...}, 'is_owning_side': bool, 'table': 'join_table'}
     *
     * '@source' is reused whenever present; otherwise the source is built from the row and the audited entity.
     *
     * Returns null when the row cannot be converted without losing or misreading its content: diffs that are not
     * valid JSON, that do not decode to an object, a field entry that is not an object, or an unknown type.
     * Such a row is left at schema_version 1, as it is, and reported.
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array<string, true>                                                                       $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>}                                               $auditedEntity table and classes audited in this table
     */
    private function convertLegacyDiffs(array $row, array $jsonFields, array $auditedEntity): ?array
    {
        try {
            $rawDiffs = json_decode((string) ($row['diffs'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($rawDiffs)) {
            return null;
        }

        return match ($row['type']) {
            'insert', 'update' => $this->convertChangeDiffs($rawDiffs, $row, $jsonFields, $auditedEntity),
            'remove' => $this->convertRemoveDiffs($rawDiffs, $row, $auditedEntity),
            'associate', 'dissociate' => $this->convertAssociateDiffs($rawDiffs),
            default => null,
        };
    }

    /**
     * Converts INSERT and UPDATE diffs.
     *
     * Field diffs ({'new': v} and/or {'old': v}) are normalized to {'new': v, 'old': v}.
     * JSON deep diffs already have the v2 shape: they are carried over unchanged and listed in 'deep_diffs'.
     * Returns null when a field entry is not an object (see convertLegacyDiffs()).
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array<string, true>                                                                       $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>}                                               $auditedEntity table and classes audited in this table
     */
    private function convertChangeDiffs(array $diffs, array $row, array $jsonFields, array $auditedEntity): ?array
    {
        $source = $this->extractLegacySource($diffs, $row, $auditedEntity);
        unset($diffs['@source']);

        $changes = [];
        $deepDiffs = [];
        foreach ($diffs as $field => $diff) {
            if (!\is_array($diff)) {
                return null;
            }

            if ($this->isFieldDiff($diff, isset($jsonFields[$field]))) {
                $changes[$field] = ['new' => $diff['new'] ?? null, 'old' => $diff['old'] ?? null];
            } else {
                $changes[$field] = $diff;
                $deepDiffs[] = (string) $field;
            }
        }

        $converted = ['source' => $source, 'changes' => $changes];
        if ([] !== $deepDiffs) {
            $converted['deep_diffs'] = $deepDiffs;
        }

        return $converted;
    }

    /**
     * Tells a field diff ({'new': v} and/or {'old': v}) apart from a JSON deep diff.
     *
     * A field diff only has 'new'/'old' keys. So may the deep diff of a JSON field, when the JSON object has
     * 'new'/'old' keys of its own. A deep diff only holds nested diffs, though, whereas the field diff of a JSON field
     * holds JSON values: since auditor 3.2.0 when one side is a scalar or null, and before it for every change, with
     * both sides whole JSON arrays or objects. So a JSON field gets a deep diff only when the diff is a deep diff node.
     * A field that is not a JSON field of the audited entity (or no longer exists) keeps the reading by keys only.
     */
    private function isFieldDiff(array $diff, bool $isJsonField): bool
    {
        if ([] === $diff || [] !== array_diff(array_keys($diff), ['new', 'old'])) {
            return false;
        }

        if (!$isJsonField) {
            return true;
        }

        // Both sides present: a deep diff holds at least one real change, whereas a field diff written before 3.2.0
        // whose values only hold empty arrays ([] to {"filters": []}) would otherwise pass for a tree of empty leaves.
        if (\array_key_exists('new', $diff) && \array_key_exists('old', $diff)) {
            return !self::isDiffNode($diff) || !self::hasChangeLeaf($diff);
        }

        return !self::isDiffNode($diff);
    }

    /**
     * Whether a diff node holds at least one leaf that is not empty ({'old': v} and/or {'new': v}).
     */
    private static function hasChangeLeaf(array $node): bool
    {
        return array_any(
            $node,
            static fn (mixed $child): bool => \is_array($child) && [] !== $child
                && ([] === array_diff(array_keys($child), ['new', 'old']) || self::hasChangeLeaf($child)),
        );
    }

    /**
     * A node of a JSON deep diff: a non-empty array whose values are leaves ({'old': v} and/or {'new': v}) or nodes.
     * A field diff holding whole JSON values is not one, as soon as one value has a scalar member.
     */
    private static function isDiffNode(mixed $node): bool
    {
        if (!\is_array($node) || [] === $node) {
            return false;
        }

        // a leaf may be an empty array: 1.x writes an empty JSON array or object as is in a deep diff
        return array_all(
            $node,
            static fn (mixed $child): bool => \is_array($child)
                && ([] === array_diff(array_keys($child), ['new', 'old']) || self::isDiffNode($child)),
        );
    }

    /**
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array{table: string, classes: list<string>}                                               $auditedEntity
     */
    private function convertRemoveDiffs(array $diffs, array $row, array $auditedEntity): array
    {
        $source = [] === $diffs ? $this->buildSource($row, $auditedEntity) : $diffs;

        return ['source' => $source, 'changes' => []];
    }

    /**
     * Returns the legacy '@source' summary when present, otherwise a source built from the row and the audited entity.
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array{table: string, classes: list<string>}                                               $auditedEntity
     */
    private function extractLegacySource(array $diffs, array $row, array $auditedEntity): array
    {
        if (isset($diffs['@source']) && \is_array($diffs['@source']) && [] !== $diffs['@source']) {
            return $diffs['@source'];
        }

        return $this->buildSource($row, $auditedEntity);
    }

    /**
     * Builds the source of a legacy entry that carries no summary of the audited entity.
     *
     * The class is the discriminator (single table inheritance), else the only class audited in the
     * table; it stays empty when several classes share the table without a discriminator. The label
     * follows the "Class#id" fallback of the auditing service: __toString() cannot be replayed here.
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array{table: string, classes: list<string>}                                               $auditedEntity
     *
     * @return array{id: string, class: string, label: string, table: string}
     */
    private function buildSource(array $row, array $auditedEntity): array
    {
        $class = $row['discriminator'] ?? '';
        if ('' === $class && 1 === \count($auditedEntity['classes'])) {
            $class = $auditedEntity['classes'][0];
        }

        return [
            'id' => $row['object_id'],
            'class' => $class,
            'label' => '' === $class ? '' : $class.'#'.$row['object_id'],
            'table' => $auditedEntity['table'],
        ];
    }

    private function convertAssociateDiffs(array $diffs): array
    {
        if (isset($diffs['table']) && !isset($diffs['join_table'])) {
            $diffs['join_table'] = $diffs['table'];
            unset($diffs['table']);
        }

        return $diffs;
    }

    /**
     * Formats a number of seconds as a human-readable duration string (e.g. "2h 15m", "3m 42s", "58s").
     */
    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return \sprintf('%ds', $seconds);
        }

        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        if ($minutes < 60) {
            return $secs > 0 ? \sprintf('%dm %ds', $minutes, $secs) : \sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins > 0 ? \sprintf('%dh %dm', $hours, $mins) : \sprintf('%dh', $hours);
    }
}
