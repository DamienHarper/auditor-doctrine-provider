<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\MigrateSchemaCommandTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates audit table schemas from v1 (legacy) to v2 (unified format).
 *
 * Schema v1 has: transaction_hash, blame_user, blame_user_fqdn, blame_user_firewall, ip
 * Schema v2 has: transaction_id (CHAR 26), blame (JSON), schema_version
 *
 * The --convert-diffs flag additionally rewrites the diffs JSON column so that
 * legacy entries (schema_version=1) are re-encoded in the new {source, changes}
 * envelope, and schema_version is updated to 2.
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
     * New columns introduced in schema v2 (column_name => added via audit:schema:update).
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
            ->addOption('convert-diffs', null, InputOption::VALUE_NONE, 'Also convert legacy diffs JSON to the new {source, changes} envelope (sets schema_version=2 on migrated rows).')
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
        $convertDiffs = true === $input->getOption('convert-diffs');

        /** @var DoctrineProvider $provider */
        $provider = $this->auditor->getProvider(DoctrineProvider::class);

        $provider->getConfiguration();

        $schemaManager = new SchemaManager($provider);

        $tables = $this->collectLegacyTables($provider, $schemaManager);

        if ([] === $tables) {
            $io->success('All audit tables are already at schema version 2. Nothing to migrate.');
            $this->release();

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Found <info>%d</info> audit table(s) to migrate:', \count($tables)));
        foreach (array_keys($tables) as $tableName) {
            $io->text(\sprintf('  - %s', $tableName));
        }

        $io->newLine();

        // Generate DDL statements per table
        $allDdlSqls = $this->generateDdlStatements($tables);

        if ([] === $allDdlSqls && !$convertDiffs) {
            $io->success('Schema is already up to date. Nothing to migrate.');
            $this->release();

            return Command::SUCCESS;
        }

        if ($dumpSql) {
            $io->text('The following SQL statements will be executed:');
            $io->newLine();
            foreach ($allDdlSqls as $tableSqls) {
                foreach ($tableSqls as $sql) {
                    $io->text(\sprintf('    %s;', $sql));
                }
            }

            if ($convertDiffs) {
                $io->text('    [+ UPDATE queries to convert diffs (executed in PHP batches)]');
            }

            $io->newLine();
        }

        if (!$force) {
            if (!$dumpSql) {
                $io->caution([
                    \sprintf('Found <info>%d</info> audit table(s) requiring schema migration.', \count($tables)),
                    '',
                    'Please run with one or both of the following options:',
                    '',
                    \sprintf('    <info>%s --force</info>           to execute the migration', $this->getName()),
                    \sprintf('    <info>%s --dump-sql</info>         to dump the SQL statements', $this->getName()),
                    \sprintf('    <info>%s --force --convert-diffs</info> to also convert legacy diffs format', $this->getName()),
                ]);
            }

            $this->release();

            return $dumpSql ? Command::SUCCESS : Command::FAILURE;
        }

        // Execute DDL statements
        foreach ($tables as $tableName => $connection) {
            $tableDdl = $allDdlSqls[$tableName] ?? [];
            if ([] !== $tableDdl) {
                $io->text(\sprintf('  Migrating schema for <info>%s</info>...', $tableName));
                foreach ($tableDdl as $sql) {
                    $connection->executeStatement($sql);
                }
            }

            if ($convertDiffs) {
                $this->convertDiffsForTable($tableName, $connection, $io);
            }
        }

        $io->newLine();
        $io->success('Audit schema migration completed successfully!');

        if ($convertDiffs) {
            $io->note('Diffs conversion complete. All migrated rows now have schema_version = 2.');
        } else {
            $io->note(
                'Existing rows have been marked with schema_version = 1 (legacy format). '
                .'Run with --convert-diffs to also convert their diffs JSON to the new format.'
            );
        }

        $this->release();

        return Command::SUCCESS;
    }

    /**
     * Returns audit tables that have the legacy schema (schema_version column absent).
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
                // A table at v1 has transaction_hash but not schema_version
                if ($table->hasColumn('transaction_hash') && !$table->hasColumn('schema_version')) {
                    $legacyTables[$auditTableName] = $connection;
                }
            }
        }

        return $legacyTables;
    }

    /**
     * Generates the DDL SQL statements needed to migrate each legacy audit table.
     * Returns an array keyed by table name, each containing an ordered list of SQL strings.
     *
     * @param array<string, Connection> $tables
     *
     * @return array<string, list<string>>
     */
    private function generateDdlStatements(array $tables): array
    {
        $allSqls = [];

        foreach ($tables as $tableName => $connection) {
            $sqls = [];
            $platform = $connection->getDatabasePlatform();
            $dbSchemaManager = $connection->createSchemaManager();
            $schema = $dbSchemaManager->introspectSchema();
            $table = $schema->getTable($tableName);

            // 1. Add schema_version column (SMALLINT NOT NULL DEFAULT 2)
            //    We add it with default 2, then immediately UPDATE existing rows to 1.
            $newColumns = SchemaHelper::getAuditTableColumns();
            foreach (self::NEW_COLUMNS as $colName) {
                if (!$table->hasColumn($colName)) {
                    $struct = $newColumns[$colName];
                    $colType = $struct['type'];
                    $colOptions = $struct['options'];

                    // For JSON columns, fall back to TEXT if JSON is not natively supported
                    if (\in_array($colType, ['json', 'json_array'], true)) {
                        $colType = 'text';
                    }

                    $fromSchema = clone $schema;
                    $table->addColumn($colName, $colType, $colOptions);
                    $diff = $dbSchemaManager->createComparator()->compareSchemas($fromSchema, $schema);
                    foreach ($platform->getAlterSchemaSQL($diff) as $sql) {
                        $sqls[] = $sql;
                    }
                }
            }

            // 2. Mark all existing rows as schema_version = 1
            $sqls[] = \sprintf('UPDATE %s SET schema_version = 1', $tableName);

            // 3. Migrate blame fields to the new blame JSON column (only if old columns exist)
            if ($table->hasColumn('blame_user')) {
                $sqls[] = $this->buildBlameMigrationSql($tableName, $connection);
            }

            // 4. Drop old indexes for transaction_hash and add new ones
            $hash = md5($tableName);
            $oldTxIdx = 'transaction_hash_'.$hash.'_idx';
            if ($table->hasIndex($oldTxIdx)) {
                $sqls[] = \sprintf('DROP INDEX %s ON %s', $oldTxIdx, $tableName);
            }

            // New indexes (transaction_id single + composites) are added via audit:schema:update.
            // We only need to clean up the old transaction_hash index here.

            // 5. Drop legacy columns
            foreach (self::LEGACY_COLUMNS as $colName) {
                if ($table->hasColumn($colName)) {
                    $fromSchema = clone $schema;
                    $table->dropColumn($colName);
                    $diff = $dbSchemaManager->createComparator()->compareSchemas($fromSchema, $schema);
                    foreach ($platform->getAlterSchemaSQL($diff) as $sql) {
                        $sqls[] = $sql;
                    }
                }
            }

            $allSqls[$tableName] = $sqls;
        }

        return $allSqls;
    }

    /**
     * Builds the SQL statement that migrates legacy blame columns into the new blame JSON column.
     * Uses platform-specific JSON_OBJECT() syntax.
     */
    private function buildBlameMigrationSql(string $tableName, Connection $connection): string
    {
        // Build a portable JSON object. We use a simplified approach:
        // if all values are null, leave blame null; otherwise build JSON manually.
        // This avoids platform-specific JSON_OBJECT() functions.
        $platform = $connection->getDatabasePlatform();
        $jsonBuild = match (true) {
            $platform instanceof MySQLPlatform, $platform instanceof MariaDBPlatform => "JSON_OBJECT('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
            $platform instanceof PostgreSQLPlatform => "json_build_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)::text",
            $platform instanceof SQLitePlatform => "json_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
            default => "json_object('username', blame_user, 'user_fqdn', blame_user_fqdn, 'user_firewall', blame_user_firewall, 'ip', ip)",
        };

        return \sprintf(
            'UPDATE %s SET blame = %s WHERE blame_user IS NOT NULL OR blame_user_fqdn IS NOT NULL OR blame_user_firewall IS NOT NULL OR ip IS NOT NULL',
            $tableName,
            $jsonBuild
        );
    }

    /**
     * Converts legacy diffs JSON rows to the new {source, changes} envelope format.
     * Processes rows in batches; updates schema_version to 2 on success.
     */
    private function convertDiffsForTable(string $tableName, Connection $connection, SymfonyStyle $io): void
    {
        $io->text(\sprintf('  Converting legacy diffs for <info>%s</info>...', $tableName));

        $offset = 0;
        $converted = 0;

        while (true) {
            $rows = $connection->fetchAllAssociative(
                \sprintf('SELECT id, type, object_id, discriminator, diffs FROM %s WHERE schema_version = 1 ORDER BY id LIMIT %d OFFSET %d', $tableName, self::BATCH_SIZE, $offset),
            );

            $batchCount = \count($rows);
            foreach ($rows as $row) {
                $newDiffs = $this->convertLegacyDiffs($row);
                $connection->update(
                    $tableName,
                    ['diffs' => json_encode($newDiffs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'schema_version' => 2],
                    ['id' => $row['id']]
                );
                ++$converted;
            }

            $offset += self::BATCH_SIZE;

            if ($batchCount < self::BATCH_SIZE) {
                break;
            }
        }

        $io->text(\sprintf('    Converted <info>%d</info> row(s).', $converted));
    }

    /**
     * Converts a single legacy diffs value to the new {source, changes} envelope.
     *
     * Legacy formats per operation type:
     *  INSERT: {'field': {'new': value}, ...}
     *  UPDATE: {'@source': {id,class,label,table}, 'field': {'new': v, 'old': v}, ...}
     *  REMOVE: {'class': ..., 'id': ..., 'label': ..., 'table': ...}
     *  ASSOCIATE/DISSOCIATE: {'source': {...}, 'target': {...}, 'is_owning_side': bool, 'table': 'join_table'}
     *
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     */
    private function convertLegacyDiffs(array $row): array
    {
        $rawDiffs = json_decode((string) ($row['diffs'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($rawDiffs)) {
            $rawDiffs = [];
        }

        $type = $row['type'];

        return match ($type) {
            'insert' => $this->convertInsertDiffs($rawDiffs, $row),
            'update' => $this->convertUpdateDiffs($rawDiffs, $row),
            'remove' => $this->convertRemoveDiffs($rawDiffs, $row),
            'associate', 'dissociate' => $this->convertAssociateDiffs($rawDiffs),
            default => $rawDiffs,
        };
    }

    /**
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     */
    private function convertInsertDiffs(array $diffs, array $row): array
    {
        $source = [
            'id' => $row['object_id'],
            'class' => $row['discriminator'] ?? '',
            'label' => '',
            'table' => '',
        ];

        $changes = [];
        foreach ($diffs as $field => $diff) {
            if (\is_array($diff) && \array_key_exists('new', $diff)) {
                $changes[$field] = ['old' => $diff['old'] ?? null, 'new' => $diff['new']];
            }
        }

        return ['source' => $source, 'changes' => $changes];
    }

    /**
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     */
    private function convertUpdateDiffs(array $diffs, array $row): array
    {
        $source = $diffs['@source'] ?? [
            'id' => $row['object_id'],
            'class' => $row['discriminator'] ?? '',
            'label' => '',
            'table' => '',
        ];
        unset($diffs['@source']);

        $changes = [];
        foreach ($diffs as $field => $diff) {
            if (\is_array($diff)) {
                $changes[$field] = ['old' => $diff['old'] ?? null, 'new' => $diff['new'] ?? null];
            }
        }

        return ['source' => $source, 'changes' => $changes];
    }

    /**
     * @param array{id: mixed, type: string, object_id: string, discriminator: ?string, diffs: ?string} $row
     */
    private function convertRemoveDiffs(array $diffs, array $row): array
    {
        // Legacy REMOVE diffs IS the source summary
        $source = $diffs;
        if ([] === $source) {
            $source = ['id' => $row['object_id'], 'class' => '', 'label' => '', 'table' => ''];
        }

        return ['source' => $source, 'changes' => []];
    }

    private function convertAssociateDiffs(array $diffs): array
    {
        // Rename 'table' (join table name) to 'join_table'
        if (isset($diffs['table']) && !isset($diffs['join_table'])) {
            $diffs['join_table'] = $diffs['table'];
            unset($diffs['table']);
        }

        return $diffs;
    }
}
