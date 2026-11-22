<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\MigrateSchemaCommandTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
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
            ->addOption('convert-all', null, InputOption::VALUE_NONE, 'Shorthand for --convert-diffs --convert-transaction-hash.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit conversions to N unique transaction hashes (--convert-transaction-hash) or N total rows (--convert-diffs). Useful for estimating migration time. When set, legacy columns are NOT dropped so the run can be resumed.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        $dumpSql = true === $input->getOption('dump-sql');
        $force = true === $input->getOption('force');
        $convertAll = true === $input->getOption('convert-all');
        $convertDiffs = $convertAll || true === $input->getOption('convert-diffs');
        $convertTransactionHash = $convertAll || true === $input->getOption('convert-transaction-hash');
        $limitRaw = $input->getOption('limit');
        $limit = \is_string($limitRaw) ? (int) $limitRaw : null;

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);
        $provider->getConfiguration();

        $schemaManager = new SchemaManager($provider);
        $tables = $this->collectLegacyTables($provider, $schemaManager);

        // When --convert-diffs is requested but DDL is already complete (transaction_hash already
        // dropped), find tables that still have schema_version=1 rows so diffs can be converted
        // independently of the hash conversion step.
        $diffsOnlyTables = [];
        if ($convertDiffs && [] === $tables) {
            $diffsOnlyTables = $this->collectDiffsConversionTables($provider, $schemaManager);
        }

        if ([] === $tables && [] === $diffsOnlyTables) {
            $io->success('All audit tables are already at schema version 2. Nothing to migrate.');
            $this->release();

            return Command::SUCCESS;
        }

        if ([] !== $tables) {
            $io->text(\sprintf('Found <info>%d</info> audit table(s) to migrate:', \count($tables)));
            foreach (array_keys($tables) as $tableName) {
                $io->text(\sprintf('  - %s', $tableName));
            }

            $io->newLine();
        } elseif ([] !== $diffsOnlyTables) {
            $io->text(\sprintf('Found <info>%d</info> table(s) with unconverted diffs (schema_version=1):', \count($diffsOnlyTables)));
            foreach (array_keys($diffsOnlyTables) as $tableName) {
                $io->text(\sprintf('  - %s', $tableName));
            }

            $io->newLine();
        }

        // Drop transaction_hash only when --convert-transaction-hash is used OR when no conversion
        // flag is given (explicit data-loss acceptance). When --convert-diffs runs alone, preserve
        // transaction_hash so --convert-transaction-hash can still be run afterwards.
        $dropTransactionHash = $convertTransactionHash || !$convertDiffs;

        $allDdlSqls = $this->generateDdlStatements($tables, $dropTransactionHash);

        // Tables to use for diffs conversion: DDL tables + diffs-only tables (already migrated DDL).
        $allDiffsTables = array_merge($tables, $diffsOnlyTables);

        $hasAdditive = [] !== array_filter(array_column($allDdlSqls, 'additive'));
        $hasDestructive = [] !== array_filter(array_column($allDdlSqls, 'destructive'));
        $hasDdl = $hasAdditive || $hasDestructive;

        if (!$hasDdl && !$convertDiffs && !$convertTransactionHash) {
            $io->success('Schema is already up to date. Nothing to migrate.');
            $this->release();

            return Command::SUCCESS;
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
                $totalTableCount = \count($tables) + \count($diffsOnlyTables);
                $io->caution([
                    \sprintf('Found <info>%d</info> audit table(s) requiring migration.', $totalTableCount),
                    '',
                    'Please run with one or more of the following options:',
                    '',
                    \sprintf('    <info>%s --force</info>                                         execute the migration', $commandName),
                    \sprintf('    <info>%s --dump-sql</info>                                       dump the SQL statements', $commandName),
                    \sprintf('    <info>%s --force --convert-diffs</info>                          also convert legacy diffs format', $commandName),
                    \sprintf('    <info>%s --force --convert-transaction-hash</info>               also convert transaction_hash to ULID (preserves grouping)', $commandName),
                    \sprintf('    <info>%s --force --convert-all</info>                            convert both diffs and transaction hash', $commandName),
                    \sprintf('    <info>%s --force --convert-all --limit=1000</info>               process a sample and estimate remaining time', $commandName),
                ]);
            }

            $this->release();

            return $dumpSql ? Command::SUCCESS : Command::FAILURE;
        }

        // Phase 1: additive DDL — add new columns, mark rows, migrate blame, drop old index
        foreach ($tables as $tableName => $connection) {
            $additiveSqls = $allDdlSqls[$tableName]['additive'] ?? [];
            if ([] !== $additiveSqls) {
                $io->text(\sprintf('  Applying additive schema changes for <info>%s</info>...', $tableName));
                foreach ($additiveSqls as $sql) {
                    $connection->executeStatement($sql);
                }
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
        if ($convertDiffs) {
            $io->newLine();
            $io->text('<comment>Converting legacy diffs...</comment>');
            $this->convertDiffsAcrossTables(
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
                    foreach ($destructiveSqls as $sql) {
                        $connection->executeStatement($sql);
                    }
                }
            }
        }

        $io->newLine();
        $io->success('Audit schema migration completed successfully!');

        if (null !== $limit) {
            $io->note(\sprintf(
                'Only %d item(s) were processed (--limit). Legacy columns were NOT dropped. Re-run without --limit to complete the migration.',
                $limit,
            ));
        } else {
            if ($convertDiffs) {
                $io->note('Diffs conversion complete. All migrated rows now have schema_version = 2.');
            } else {
                // Only suggest --convert-diffs if there are actually schema_version=1 rows remaining.
                $hasLegacyDiffsRows = $this->hasLegacyDiffsRows($allDiffsTables);
                if ($hasLegacyDiffsRows) {
                    $io->note(
                        'Existing rows have been marked with schema_version = 1 (legacy format). '
                        .'Run with --convert-diffs to also convert their diffs JSON to the new format.',
                    );
                }
            }

            // Only warn about transaction_hash data loss when DDL tables were actually processed
            // (i.e., transaction_hash was present and dropped in this run).
            if ([] !== $tables && !$convertTransactionHash && $dropTransactionHash) {
                $io->note(
                    'Legacy transaction_hash values have been dropped without conversion. '
                    .'To preserve transactional grouping, use --convert-transaction-hash before dropping legacy columns.',
                );
            } elseif ([] !== $tables && !$convertTransactionHash && !$dropTransactionHash) {
                $io->note(
                    'transaction_hash has been preserved. Run with --convert-transaction-hash to convert it to transaction_id (ULID).',
                );
            }
        }

        $this->release();

        return Command::SUCCESS;
    }

    /**
     * Returns audit tables that still have the legacy transaction_hash column.
     * Detects both fully v1 tables and partially migrated tables (schema_version added but
     * transaction_hash not yet dropped), enabling safe resumption after an interrupted run.
     *
     * @return array<string, Connection> keyed by table name
     */
    private function collectLegacyTables(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        /** @var StorageService[] $storageServices */
        $storageServices = $provider->getStorageServices();
        $legacyTables = [];

        foreach ($storageServices as $storageService) {
            $connection = $storageService->getEntityManager()->getConnection();
            $dbSchemaManager = $connection->createSchemaManager();
            $schema = $dbSchemaManager->introspectSchema();

            /** @var Configuration $configuration */
            $configuration = $provider->getConfiguration();

            foreach (array_keys($configuration->getEntities()) as $entity) {
                $auditTableName = $schemaManager->resolveAuditTableName($entity, $configuration);
                if (null === $auditTableName) {
                    continue;
                }

                if (!$schema->hasTable($auditTableName)) {
                    continue;
                }

                $table = $schema->getTable($auditTableName);
                // Any table that still has transaction_hash needs migration (fully v1 or partially migrated)
                if ($table->hasColumn('transaction_hash')) {
                    $legacyTables[$auditTableName] = $connection;
                }
            }
        }

        return $legacyTables;
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
     * Returns true if any of the given tables still contain rows with schema_version=1.
     *
     * @param array<string, Connection> $tables
     */
    private function hasLegacyDiffsRows(array $tables): bool
    {
        foreach ($tables as $tableName => $connection) {
            $count = $connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE schema_version = 1', $tableName),
            );
            if (is_numeric($count) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns audit tables that have schema_version=1 rows but whose DDL migration is already
     * complete (transaction_hash has already been dropped). Used to allow --convert-diffs to run
     * after --convert-transaction-hash has been applied in a previous invocation.
     *
     * @return array<string, Connection> keyed by table name
     */
    private function collectDiffsConversionTables(DoctrineProvider $provider, SchemaManager $schemaManager): array
    {
        /** @var StorageService[] $storageServices */
        $storageServices = $provider->getStorageServices();
        $tables = [];

        /** @var Configuration $configuration */
        $configuration = $provider->getConfiguration();

        foreach ($storageServices as $storageService) {
            $connection = $storageService->getEntityManager()->getConnection();
            $dbSchemaManager = $connection->createSchemaManager();
            $schema = $dbSchemaManager->introspectSchema();

            foreach (array_keys($configuration->getEntities()) as $entity) {
                $auditTableName = $schemaManager->resolveAuditTableName($entity, $configuration);
                if (null === $auditTableName) {
                    continue;
                }

                if (!$schema->hasTable($auditTableName)) {
                    continue;
                }

                $table = $schema->getTable($auditTableName);
                // Only consider tables whose DDL is fully applied (no legacy transaction_hash column)
                // but that still carry unconverted rows (schema_version=1).
                if ($table->hasColumn('transaction_hash')) {
                    continue;
                }

                if (!$table->hasColumn('schema_version')) {
                    continue;
                }

                $count = $connection->fetchOne(
                    \sprintf('SELECT COUNT(*) FROM %s WHERE schema_version = 1', $auditTableName),
                );

                if (is_numeric($count) && (int) $count > 0) {
                    $tables[$auditTableName] = $connection;
                }
            }
        }

        return $tables;
    }

    /**
     * Generates additive and destructive DDL SQL statements per legacy audit table.
     *
     * Additive: add new columns (idempotent — skips already-present columns),
     *           existing rows get schema_version=1, migrate blame columns to JSON.
     * Destructive: drop the indexes covering legacy columns, then the legacy columns (transaction_hash, blame_user, …).
     *
     * @param array<string, Connection> $tables
     * @param bool                      $dropTransactionHash Whether to include transaction_hash in the destructive phase.
     *                                                       Pass false when --convert-diffs runs without --convert-transaction-hash
     *                                                       so that transaction_hash is preserved for a subsequent conversion run.
     *
     * @return array<string, array{additive: list<string>, destructive: list<string>}>
     */
    private function generateDdlStatements(array $tables, bool $dropTransactionHash = true): array
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
                    // same statement, so that a run interrupted right after it cannot leave them at 2.
                    // audit:schema:update sets the default of the v2 schema afterwards.
                    if ('schema_version' === $colName) {
                        $colOptions['default'] = 1;
                    }

                    $column = SchemaHelper::buildColumn($colName, $colType, $colOptions);
                    foreach ($alter(static fn (TableEditor $editor): TableEditor => $editor->addColumn($column)) as $sql) {
                        $additive[] = $sql;
                    }
                }
            }

            // 2. Migrate blame fields to the new blame JSON column (idempotent — WHERE filters nulls)
            if ($table->hasColumn('blame_user')) {
                $additive[] = $this->buildBlameMigrationSql($tableName, $connection);
            }

            // 3. Drop legacy columns (destructive — executed after conversions, and only when --limit is not set).
            // Skip transaction_hash if it should be preserved for a subsequent conversion run.
            $droppedColumns = array_values(array_filter(
                self::LEGACY_COLUMNS,
                static fn (string $colName): bool => $table->hasColumn($colName) && ($dropTransactionHash || 'transaction_hash' !== $colName),
            ));

            // Indexes covering a dropped column are dropped first: the transaction_hash index of v1 tables,
            // the (blame_user, blame_id) index of provider 1.x, or any custom one. MySQL and MariaDB would
            // otherwise keep them on the remaining columns under their legacy name.
            // The transaction_hash index goes with its column, not in the additive phase:
            // --convert-transaction-hash looks rows up by transaction_hash, and would otherwise
            // scan the whole table once per legacy transaction.
            foreach ($table->getIndexes() as $index) {
                $indexedColumns = array_map(
                    static fn (IndexedColumn $column): string => mb_strtolower($column->getColumnName()->getIdentifier()->getValue()),
                    $index->getIndexedColumns(),
                );

                if ([] === array_intersect($indexedColumns, $droppedColumns)) {
                    continue;
                }

                $indexName = $index->getObjectName();
                foreach ($alter(static fn (TableEditor $editor): TableEditor => $editor->dropIndex($indexName)) as $sql) {
                    $destructive[] = $sql;
                }
            }

            // transaction_hash goes last: a table keeps being detected as a legacy table until it is dropped,
            // so a run interrupted between two of these statements (MySQL and MariaDB do not roll DDL back)
            // is completed by the next one.
            usort($droppedColumns, static fn (string $a, string $b): int => ('transaction_hash' === $a) <=> ('transaction_hash' === $b));

            foreach ($droppedColumns as $colName) {
                $columnName = $table->getColumn($colName)->getObjectName();
                foreach ($alter(static fn (TableEditor $editor): TableEditor => $editor->dropColumn($columnName)) as $sql) {
                    $destructive[] = $sql;
                }
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
     * Algorithm:
     *   Phase A — build a global hash→ULID map: collect all distinct transaction_hash values
     *             across every audit table (WHERE transaction_id IS NULL AND transaction_hash IS NOT NULL),
     *             deduplicate globally, generate exactly ONE Ulid per unique hash.
     *             If --limit=N, take only the first N entries.
     *   Phase B — apply the map to every table: UPDATE … SET transaction_id = :ulid
     *             WHERE transaction_hash = :hash AND transaction_id IS NULL.
     *
     * The cross-table deduplication ensures that entries from the same original transaction
     * (same hash in post_audit, comment_audit, tag_audit, …) all receive the identical ULID,
     * preserving the grouping semantic.
     *
     * Idempotent: already-converted rows (transaction_id IS NOT NULL) are never touched.
     * --limit unit: number of unique hashes (never splits a transaction group).
     *
     * @param array<string, Connection> $tables
     */
    private function convertTransactionHashAcrossTables(array $tables, ?int $limit, SymfonyStyle $io): void
    {
        // Phase A: build global hash→ULID map across all tables
        /** @var array<string, string> $hashMap  hash => ulid */
        $hashMap = [];

        // ULIDs already given to a hash by an interrupted run: reusing them keeps the transaction
        // grouped across the tables that run had converted and the ones it had not reached.
        /** @var array<string, string> $convertedHashes hash => ulid */
        $convertedHashes = [];
        foreach ($tables as $tableName => $connection) {
            if (!$connection->createSchemaManager()->introspectSchema()->getTable($tableName)->hasColumn('transaction_hash')) {
                continue;
            }

            $pairs = $connection->fetchAllNumeric(\sprintf(
                'SELECT DISTINCT transaction_hash, transaction_id FROM %s WHERE transaction_id IS NOT NULL AND transaction_hash IS NOT NULL',
                $tableName,
            ));

            foreach ($pairs as [$hash, $ulid]) {
                $convertedHashes[(string) $hash] ??= (string) $ulid;
            }
        }

        foreach ($tables as $tableName => $connection) {
            $dbSchemaManager = $connection->createSchemaManager();
            $schema = $dbSchemaManager->introspectSchema();
            if (!$schema->getTable($tableName)->hasColumn('transaction_hash')) {
                continue;
            }

            /** @var list<mixed> $hashes */
            $hashes = $connection->fetchFirstColumn(
                \sprintf(
                    'SELECT DISTINCT transaction_hash FROM %s WHERE transaction_id IS NULL AND transaction_hash IS NOT NULL',
                    $tableName,
                ),
            );

            foreach ($hashes as $raw) {
                $hash = (string) $raw;
                $hashMap[$hash] ??= $convertedHashes[$hash] ?? (string) new Ulid();
            }
        }

        $totalHashes = \count($hashMap);

        if (0 === $totalHashes) {
            $io->text('  No transaction_hash values to convert.');

            return;
        }

        if (null !== $limit) {
            $hashMap = \array_slice($hashMap, 0, $limit, true);
        }

        // Phase B: apply map to all tables
        $totalRows = 0;
        $tablesUpdated = 0;
        $startTime = microtime(true);

        foreach ($tables as $tableName => $connection) {
            $dbSchemaManager = $connection->createSchemaManager();
            $schema = $dbSchemaManager->introspectSchema();
            if (!$schema->getTable($tableName)->hasColumn('transaction_hash')) {
                continue;
            }

            $tableRows = 0;
            $sql = \sprintf('UPDATE %s SET transaction_id = ? WHERE transaction_hash = ? AND transaction_id IS NULL', $tableName);

            // One database transaction per BATCH_SIZE hashes: in autocommit mode, each UPDATE would be
            // committed (and its log flushed to disk) on its own, which dominates the duration of the conversion.
            foreach (array_chunk($hashMap, self::BATCH_SIZE, true) as $chunk) {
                $tableRows += $connection->transactional(static function (Connection $connection) use ($chunk, $sql): int {
                    $affected = 0;
                    foreach ($chunk as $hash => $ulid) {
                        $affected += (int) $connection->executeStatement($sql, [$ulid, $hash]);
                    }

                    return $affected;
                });
            }

            if ($tableRows > 0) {
                ++$tablesUpdated;
                $totalRows += $tableRows;
            }
        }

        $elapsed = microtime(true) - $startTime;
        $processed = \count($hashMap);

        $io->text(\sprintf(
            '  Converted <info>%d</info> / %d unique hash(es) → <info>%d</info> row(s) updated across <info>%d</info> table(s) in %.1fs.',
            $processed,
            $totalHashes,
            $totalRows,
            $tablesUpdated,
            $elapsed,
        ));

        if (null !== $limit && $processed < $totalHashes) {
            $remaining = $totalHashes - $processed;
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
     * Entities that share an audit table (single table inheritance) add up their fields.
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
     */
    private function convertDiffsAcrossTables(array $tables, array $jsonFields, array $auditedEntities, ?int $limit, SymfonyStyle $io): void
    {
        // Count total rows to convert across all tables for estimation
        $totalRows = 0;
        foreach ($tables as $tableName => $connection) {
            $count = $connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE schema_version = 1', $tableName),
            );
            if (is_numeric($count)) {
                $totalRows += (int) $count;
            }
        }

        if (0 === $totalRows) {
            $io->text('  No legacy diffs rows to convert.');

            return;
        }

        $startTime = microtime(true);
        $convertedTotal = 0;
        $remaining = $limit;

        foreach ($tables as $tableName => $connection) {
            if (null !== $remaining && $remaining <= 0) {
                break;
            }

            $auditedEntity = $auditedEntities[$tableName] ?? ['table' => '', 'classes' => []];
            $convertedInTable = $this->convertDiffsForTable($tableName, $connection, $jsonFields[$tableName] ?? [], $auditedEntity, $io, $remaining);
            $convertedTotal += $convertedInTable;

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

        if (null !== $limit && $convertedTotal < $totalRows) {
            $leftRows = $totalRows - $convertedTotal;
            $estimatedSeconds = $convertedTotal > 0 ? (int) round($elapsed * $leftRows / $convertedTotal) : 0;
            $io->text(\sprintf(
                '  → Estimated remaining: ~%s (%d row(s) left)',
                $this->formatSeconds($estimatedSeconds),
                $leftRows,
            ));
        }
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
     *
     * @param array<string, true>                         $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>} $auditedEntity table and classes audited in this table
     * @param ?int                                        $limit         Maximum number of rows to convert (null = no limit)
     *
     * @return int Number of rows converted
     */
    private function convertDiffsForTable(string $tableName, Connection $connection, array $jsonFields, array $auditedEntity, SymfonyStyle $io, ?int $limit): int
    {
        $io->text(\sprintf('  Converting legacy diffs for <info>%s</info>...', $tableName));

        $converted = 0;
        $lastId = 0;

        while (true) {
            $batchLimit = self::BATCH_SIZE;
            if (null !== $limit) {
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
                $updates[] = [
                    'id' => $row['id'],
                    'diffs' => json_encode($this->convertLegacyDiffs($row, $jsonFields, $auditedEntity), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
            }

            $connection->transactional(static function (Connection $connection) use ($tableName, $updates): void {
                foreach ($updates as $update) {
                    $connection->update($tableName, ['diffs' => $update['diffs'], 'schema_version' => 2], ['id' => $update['id']]);
                }
            });

            $converted += \count($updates);
            $lastId = $updates[\count($updates) - 1]['id'];

            if (\count($rows) < $batchLimit) {
                break;
            }
        }

        $io->text(\sprintf('    Converted <info>%d</info> row(s).', $converted));

        return $converted;
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
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array<string, true>                                                                       $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>}                                               $auditedEntity table and classes audited in this table
     */
    private function convertLegacyDiffs(array $row, array $jsonFields, array $auditedEntity): array
    {
        $rawDiffs = json_decode((string) ($row['diffs'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($rawDiffs)) {
            $rawDiffs = [];
        }

        return match ($row['type']) {
            'insert', 'update' => $this->convertChangeDiffs($rawDiffs, $row, $jsonFields, $auditedEntity),
            'remove' => $this->convertRemoveDiffs($rawDiffs, $row, $auditedEntity),
            'associate', 'dissociate' => $this->convertAssociateDiffs($rawDiffs),
            default => $rawDiffs,
        };
    }

    /**
     * Converts INSERT and UPDATE diffs.
     *
     * Field diffs ({'new': v} and/or {'old': v}) are normalized to {'new': v, 'old': v}.
     * JSON deep diffs already have the v2 shape: they are carried over unchanged and listed in 'deep_diffs'.
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     * @param array<string, true>                                                                       $jsonFields    JSON fields of the entities audited in this table
     * @param array{table: string, classes: list<string>}                                               $auditedEntity table and classes audited in this table
     */
    private function convertChangeDiffs(array $diffs, array $row, array $jsonFields, array $auditedEntity): array
    {
        $source = $this->extractLegacySource($diffs, $row, $auditedEntity);
        unset($diffs['@source']);

        $changes = [];
        $deepDiffs = [];
        foreach ($diffs as $field => $diff) {
            if (!\is_array($diff)) {
                continue;
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
     * A field diff only has 'new'/'old' keys. So has the deep diff of a JSON object whose own keys are
     * 'new'/'old', but the values of a deep diff are always nested diffs (arrays), whereas a JSON field
     * only gets a field diff when one of its sides is a scalar. A field that is not a JSON field of the
     * audited entity (or no longer exists) keeps the reading by keys only.
     */
    private function isFieldDiff(array $diff, bool $isJsonField): bool
    {
        if ([] === $diff || [] !== array_diff(array_keys($diff), ['new', 'old'])) {
            return false;
        }

        if (!$isJsonField) {
            return true;
        }

        return array_any($diff, static fn (mixed $value): bool => !\is_array($value));
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
