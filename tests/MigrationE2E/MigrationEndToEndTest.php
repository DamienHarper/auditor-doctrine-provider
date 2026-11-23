<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Auditing\Root\RootIndexRebuilder;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\UpdateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\Service\StorageServiceInterface;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Author;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Bike;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Comment;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Post;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Environment;
use DH\Auditor\Tests\MigrationE2E\Fixtures\FailingStatementMiddleware;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Type\MoneyType;
use DH\Auditor\User\User;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Ulid;

/**
 * End-to-end test of the migration from provider 1.x to 2.x (audit:schema:migrate).
 *
 * Each test has provider 1.x write audit entries (tools/migration-e2e/v1/generate.php, run in its own process),
 * migrates them with this version, then checks that nothing was lost or altered: same rows, same blame, same
 * transactions, same changes, and a schema that audit:schema:update leaves alone.
 *
 * Not part of the default test suite. Run it with:
 *     composer install --working-dir=tools/migration-e2e/v1
 *     E2E_DATABASE_URL=<url> vendor/bin/phpunit --group migration-e2e   (SQLite files without E2E_DATABASE_URL)
 * or make migration-e2e db=<sqlite|mysql|pgsql|mariadb>.
 *
 * @internal
 */
#[Group('migration-e2e')]
final class MigrationEndToEndTest extends TestCase
{
    private const array LEGACY_COLUMNS = ['transaction_hash', 'blame_user', 'blame_user_fqdn', 'blame_user_firewall', 'ip'];

    /** Fields mapped to a JSON type in the fixtures. */
    private const array JSON_FIELDS = ['settings'];

    /** @var array<string, array<string, list<array<string, mixed>>>> v1 rows per storage and audit table */
    private array $snapshot;

    private DoctrineProvider $provider;

    /** @var array<string, EntityManagerInterface> */
    private array $entityManagers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateV1Data();
        $this->provider = $this->createProvider();
    }

    protected function tearDown(): void
    {
        FailingStatementMiddleware::$pattern = null;
        foreach ($this->entityManagers as $entityManager) {
            $entityManager->getConnection()->close();
        }

        parent::tearDown();
    }

    /**
     * The migration documented in the upgrade guide: audit:schema:migrate --force --convert-all,
     * then audit:schema:update --force, then the check: audit:schema:migrate without options.
     */
    public function testCompleteMigration(): void
    {
        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true]));
        $this->assertMigrated();

        // a second run finds nothing to do and changes nothing
        $state = $this->databaseState();
        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true]), 'second run');
        $this->assertSame($state, $this->databaseState(), 'A second run must not change anything.');

        // audit:schema:update brings the tables to the 2.0 schema without touching the data...
        $this->assertSame(Command::SUCCESS, $this->updateSchema(['--force' => true]));
        $this->assertMigrated();

        // ...and has nothing left to do afterwards
        $tester = $this->updateSchemaTester(['--dump-sql' => true]);
        $this->assertStringContainsString('Nothing to update', $tester->getDisplay(), 'The migrated tables must match the 2.0 schema.');
        $this->assertSame(Command::SUCCESS, $this->migrate([]), 'The check of the upgrade guide must pass.');

        $this->assertReadable();
        $this->assertRootIndexRebuilt();
    }

    /**
     * Without --force, the command only describes the migration.
     */
    public function testRunsWithoutForceChangeNothing(): void
    {
        $state = $this->databaseState();

        $this->assertSame(Command::FAILURE, $this->migrate([]));
        $this->assertSame(Command::SUCCESS, $this->migrate(['--dump-sql' => true]));
        $this->assertSame(Command::SUCCESS, $this->migrate(['--dump-sql' => true, '--convert-all' => true, '--limit' => '10']));

        $this->assertSame($state, $this->databaseState());
    }

    /**
     * Runs limited with --limit (to estimate the duration) convert what they are allowed to and keep the legacy
     * columns, then a complete run finishes.
     */
    public function testLimitedRunsThenCompleteRun(): void
    {
        $convertedRows = 0;
        $rowsWithTransactionId = 0;
        for ($run = 1; $run <= 3; ++$run) {
            $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true, '--limit' => '250']), 'limited run '.$run);
            $this->assertSame(Command::FAILURE, $this->migrate([]), 'The check of the upgrade guide must fail after a limited run.');

            // the diffs of exactly 250 more rows, and more transactions, are converted
            $this->assertSame($convertedRows + 250, $convertedRows = $this->countRows('schema_version = 2'), 'rows converted by limited run '.$run);
            $this->assertGreaterThan($rowsWithTransactionId, $rowsWithTransactionId = $this->countRows('transaction_id IS NOT NULL'), 'transactions converted by limited run '.$run);

            if (1 === $run) {
                // a transaction is found by its id, converted (ULID) or not yet (legacy hash)
                $unconverted = $this->connection(Environment::PRIMARY)->fetchOne('SELECT transaction_hash FROM e2e_translation_audit WHERE transaction_id IS NULL AND transaction_hash IS NOT NULL');
                $this->assertIsString($unconverted, 'The first limited run must leave transactions to convert.');
                $this->assertNotSame([], new Reader($this->provider)->getAuditsByTransactionId($unconverted), 'unconverted transaction');
            }

            foreach ($this->auditTables() as [$connection, $table]) {
                $this->assertContains('transaction_hash', $this->columns($connection, $table), \sprintf('%s: a limited run must keep the legacy columns.', $table));

                // rows read from a half-migrated table keep a transaction id, converted or not
                foreach ($connection->fetchAllAssociative(\sprintf('SELECT * FROM %s', $table)) as $row) {
                    $this->assertNotNull($this->entry($row)->transactionId, \sprintf('%s #%s: transaction id', $table, $row['id']));
                }
            }
        }

        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true]));
        $this->assertMigrated();
    }

    /**
     * A run that fails half-way, while converting the diffs of the second batch of a table, is resumed by the next one.
     */
    public function testFailedRunIsResumed(): void
    {
        $connection = $this->connection(Environment::PRIMARY);
        $table = 'e2e_translation_audit';
        $first = $this->snapshot[Environment::PRIMARY][$table][0];
        $row = $this->snapshot[Environment::PRIMARY][$table][800];
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            // SQLite cannot add a constraint to an existing table: a trigger refuses the converted diffs of the row
            $connection->executeStatement(\sprintf(
                "CREATE TRIGGER e2e_failing_row BEFORE UPDATE ON %s WHEN NEW.object_id = '%s' AND NEW.diffs LIKE '%%\"changes\"%%' BEGIN SELECT RAISE(ABORT, 'e2e_failing_row'); END",
                $table,
                $row['object_id'],
            ));
            $restore = static fn (): int|string => $connection->executeStatement('DROP TRIGGER e2e_failing_row');
        } else {
            // the other platforms only accept valid JSON: a constraint refuses the converted diffs of the row
            // (MySQL and MariaDB do not let a CHECK constraint refer to the auto-increment id)
            $diffs = $platform instanceof PostgreSQLPlatform ? 'diffs::text' : 'CAST(diffs AS CHAR)';
            $connection->executeStatement(\sprintf(
                "ALTER TABLE %s ADD CONSTRAINT e2e_failing_row CHECK (object_id <> '%s' OR %s NOT LIKE '%%\"changes\"%%')",
                $table,
                $row['object_id'],
                $diffs,
            ));
            $restore = static fn (): int|string => $connection->executeStatement(\sprintf('ALTER TABLE %s DROP CONSTRAINT e2e_failing_row', $table));
        }

        try {
            $this->migrate(['--force' => true, '--convert-all' => true]);
            $this->fail('The migration was expected to fail on row '.$row['id'].'.');
        } catch (DbalException $dbalException) {
            $this->assertStringContainsString('e2e_failing_row', $dbalException->getMessage(), 'The migration must fail on the row made to fail, not on something else.');
        }

        // the rows of the failing batch are left unconverted, those of the previous batch are converted
        $versions = $connection->fetchAllKeyValue(\sprintf('SELECT id, schema_version FROM %s WHERE id IN (?, ?)', $table), [$row['id'], $first['id']]);
        $this->assertSame(1, (int) $versions[$row['id']], 'row of the failing batch');
        $this->assertSame(2, (int) $versions[$first['id']], 'row of the first batch');

        $restore();
        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true]));
        $this->assertMigrated();
    }

    /**
     * A table alteration interrupted in the middle (SQLite rebuilds a table to drop a column) loses nothing on the
     * platforms whose DDL is transactional, and the next run completes the migration. MySQL and MariaDB commit each
     * DDL statement: there, the statements are ordered so that the next run completes them.
     */
    public function testInterruptedTableAlterationLosesNothing(): void
    {
        $connection = $this->connection(Environment::PRIMARY);
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof SQLitePlatform && !$platform instanceof PostgreSQLPlatform) {
            $this->markTestSkipped('DDL statements are not transactional on MySQL and MariaDB.');
        }

        $rows = \count($this->snapshot[Environment::PRIMARY]['e2e_author_audit']);
        FailingStatementMiddleware::$pattern = $platform instanceof SQLitePlatform
            ? '/^INSERT INTO e2e_author_audit .*__temp__/is'                      // copying the rows back into the rebuilt table
            : '/^ALTER TABLE e2e_author_audit\b.*\btransaction_hash\b/is';       // dropping transaction_hash

        try {
            $this->migrate(['--force' => true, '--convert-all' => true]);
            $this->fail('The table alteration was expected to fail.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('Statement made to fail', $runtimeException->getMessage());
        }

        $columns = $this->columns($connection, 'e2e_author_audit');
        $this->assertContains('transaction_hash', $columns, 'The interrupted alteration must be rolled back.');
        $this->assertContains('blame_user', $columns, 'The interrupted alteration must be rolled back.');
        $this->assertSame($rows, (int) $connection->fetchOne('SELECT COUNT(*) FROM e2e_author_audit'), 'No row may be lost.');

        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-all' => true]));
        $this->assertMigrated();
    }

    /**
     * Converting the transaction hashes first and the diffs later, with 2.x writing entries in between:
     * the tables mix v1 and v2 rows until the second run, and must stay readable.
     */
    public function testStepByStepMigrationWithWritesInBetween(): void
    {
        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-transaction-hash' => true]));
        $this->assertMigrated(diffsConverted: false);
        $this->assertReadable();
        $this->assertSame(Command::FAILURE, $this->migrate([]), 'The check of the upgrade guide must fail while diffs are left.');

        $newRows = $this->writeWithV2();

        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-diffs' => true]));
        $this->assertMigrated();
        $this->assertSame($newRows, $this->rowsWrittenByV2(), 'The 2.x entry must be left as it was written.');

        $this->assertSame(Command::SUCCESS, $this->updateSchema(['--force' => true]));
        $this->assertMigrated();
        $this->assertReadable();
        $this->assertSame(Command::SUCCESS, $this->migrate([]));
    }

    /**
     * The other way round: the diffs first, transaction_hash kept, 2.x writing entries in between, then the hashes.
     */
    public function testDiffsFirstThenTransactionHashesWithWritesInBetween(): void
    {
        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-diffs' => true]));
        foreach ($this->auditTables() as [$connection, $table]) {
            $columns = $this->columns($connection, $table);
            $this->assertContains('transaction_hash', $columns, $table.': transaction_hash is kept until converted.');
            $this->assertNotContains('blame_user', $columns, $table.': the migrated blame columns are dropped.');
        }

        $this->assertSame(Command::FAILURE, $this->migrate([]), 'The check of the upgrade guide must fail while transaction_hash is left.');

        $newRows = $this->writeWithV2();
        $this->assertNull($newRows[0]['transaction_hash']);

        $this->assertSame(Command::SUCCESS, $this->migrate(['--force' => true, '--convert-transaction-hash' => true]));
        $this->assertMigrated();
        $this->assertSame(
            array_map(static fn (array $row): array => array_diff_key($row, ['transaction_hash' => true]), $newRows),
            $this->rowsWrittenByV2(),
            'The 2.x entry must be left as it was written.',
        );

        $this->assertSame(Command::SUCCESS, $this->updateSchema(['--force' => true]));
        $this->assertMigrated();
        $this->assertReadable();
        $this->assertSame(Command::SUCCESS, $this->migrate([]));
    }

    /**
     * Two audit tables of the same name on two storages would be converted as one: the migration refuses to start.
     */
    public function testAuditTableOnTwoStoragesIsRefused(): void
    {
        $this->connection(Environment::SECONDARY)->executeStatement('CREATE TABLE e2e_vehicle_audit (id INTEGER PRIMARY KEY, transaction_hash VARCHAR(40))');
        $this->provider->getConfiguration()->setStorageMapper(
            static fn (string $entity, array $storageServices): StorageServiceInterface => $storageServices[
                Bike::class === $entity || \in_array($entity, Environment::secondaryEntities(), true) ? Environment::SECONDARY : Environment::PRIMARY
            ]
        );
        $state = $this->databaseState();

        try {
            $this->migrate(['--force' => true, '--convert-all' => true]);
            $this->fail('The migration was expected to refuse an audit table found on two storages.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('e2e_vehicle_audit exists on several storages', $runtimeException->getMessage());
        }

        $this->assertSame($state, $this->databaseState(), 'Nothing may have been migrated.');
    }

    /**
     * Has 2.x write an entry in the half-migrated tables.
     *
     * @return list<array<string, mixed>> the audit row it wrote
     */
    private function writeWithV2(): array
    {
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): User => new User('5', 'new.user'));
        $entityManager = $this->entityManagers[Environment::PRIMARY];
        $author = new Author('Written by 2.x');
        $author->settings = ['written' => 'by 2.x'];

        $entityManager->persist($author);
        $entityManager->flush();

        $newRows = $this->rowsWrittenByV2();
        $this->assertCount(1, $newRows);
        $this->assertSame(2, (int) $newRows[0]['schema_version']);

        return $newRows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsWrittenByV2(): array
    {
        $lastLegacyId = max(array_map(static fn (array $row): int => (int) $row['id'], $this->snapshot[Environment::PRIMARY]['e2e_author_audit']));

        return $this->connection(Environment::PRIMARY)->fetchAllAssociative('SELECT * FROM e2e_author_audit WHERE id > ? ORDER BY id', [$lastLegacyId]);
    }

    /**
     * Counts the audit rows of every table matching a condition.
     */
    private function countRows(string $condition): int
    {
        $count = 0;
        foreach ($this->auditTables() as [$connection, $table]) {
            $count += (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $condition));
        }

        return $count;
    }

    /**
     * Compares every audit row with the row 1.x wrote.
     */
    private function assertMigrated(bool $diffsConverted = true): void
    {
        $extractor = new ChangeExtractor();
        $ulidOfHash = [];
        $hashOfUlid = [];

        foreach ($this->snapshot as $storage => $tables) {
            $connection = $this->connection($storage);

            foreach ($tables as $table => $legacyRows) {
                $columns = $this->columns($connection, $table);
                $this->assertSame([], array_values(array_intersect(self::LEGACY_COLUMNS, $columns)), \sprintf('%s: legacy columns left.', $table));

                $rows = [];
                foreach ($connection->fetchAllAssociative(\sprintf('SELECT * FROM %s ORDER BY id', $table)) as $row) {
                    $rows[(string) $row['id']] = $row;
                }

                foreach ($legacyRows as $legacy) {
                    $id = (string) $legacy['id'];
                    $where = \sprintf('%s #%s', $table, $id);
                    $this->assertArrayHasKey($id, $rows, $where.' is missing.');
                    $row = $rows[$id];

                    foreach (['type', 'object_id', 'discriminator', 'blame_id'] as $column) {
                        $this->assertSame($this->scalar($legacy[$column]), $this->scalar($row[$column]), \sprintf('%s: %s', $where, $column));
                    }

                    $this->assertSame($this->timestamp($legacy['created_at']), $this->timestamp($row['created_at']), $where.': created_at');
                    $this->assertSame($this->sorted($this->json($legacy['extra_data'])), $this->sorted($this->json($row['extra_data'])), $where.': extra_data');

                    // blame: the four legacy columns, in the blame JSON column
                    $legacyBlame = [ // sorted by key, as compared
                        'ip' => $legacy['ip'],
                        'user_firewall' => $legacy['blame_user_firewall'],
                        'user_fqdn' => $legacy['blame_user_fqdn'],
                        'username' => $legacy['blame_user'],
                    ];
                    $expectedBlame = [] === array_filter($legacyBlame, static fn (mixed $value): bool => null !== $value) ? null : $legacyBlame;
                    $this->assertSame($expectedBlame, $this->sorted($this->json($row['blame'])), $where.': blame');

                    // transaction: one ULID per legacy hash, across tables and storages
                    $ulid = $row['transaction_id'];
                    $this->assertIsString($ulid, $where.': transaction_id');
                    $this->assertTrue(Ulid::isValid($ulid), $where.': transaction_id is not a ULID: '.$ulid);
                    $hash = $legacy['transaction_hash'];
                    $ulidOfHash[$hash] ??= $ulid;
                    $hashOfUlid[$ulid] ??= $hash;
                    $this->assertSame($ulidOfHash[$hash], $ulid, $where.': the rows of a legacy transaction got different ULIDs.');
                    $this->assertSame($hashOfUlid[$ulid], $hash, $where.': two legacy transactions got the same ULID.');

                    $this->assertSame($diffsConverted ? 2 : 1, (int) $row['schema_version'], $where.': schema_version');
                    if ($diffsConverted) {
                        $this->assertConvertedDiffs($legacy, $row, $where);
                    } else {
                        $this->assertSame($this->json($legacy['diffs']), $this->json($row['diffs']), $where.': diffs must be left as they are until --convert-diffs.');
                    }

                    // what the history API reads from the entry is unchanged, except the class of the audited
                    // entity, which it cannot tell from a v1 entry and reads from the source of a converted one
                    $before = $this->changes($extractor, $this->entry($legacy));
                    $after = $this->changes($extractor, $this->entry($row));

                    // Known limitation of the history API on unconverted v1 entries: the deep diff of a JSON object
                    // whose own keys are "new" and "old" cannot be told from a field diff without the entity mapping,
                    // and is read as one. The converted entry is read right (checked by assertConvertedDiffs()).
                    $ambiguous = $this->ambiguousJsonFields($legacy);
                    if ([] !== $ambiguous) {
                        $keep = static fn (array $change): bool => !\in_array($change['field'], $ambiguous, true);
                        $before = array_values(array_filter($before, $keep));
                        $after = array_values(array_filter($after, $keep));
                    }

                    foreach ($before as $i => $change) {
                        if ($diffsConverted && '' === $change['entity'] && isset($after[$i])) {
                            $this->assertNotSame('', $after[$i]['entity'], $where.': class of the audited entity');
                            $before[$i]['entity'] = $after[$i]['entity'];
                        }
                    }

                    $this->assertSame($before, $after, $where.': the changes read from the entry differ.');

                    // the blame shortcuts of the read model
                    $entry = $this->entry($row);
                    $this->assertSame($legacy['blame_user'], $entry->username, $where.': username');
                    $this->assertSame($legacy['ip'], $entry->ip, $where.': ip');
                }
            }
        }
    }

    /**
     * The converted diffs carry every legacy value unchanged.
     *
     * @param array<string, mixed> $legacy
     * @param array<string, mixed> $row
     */
    private function assertConvertedDiffs(array $legacy, array $row, string $where): void
    {
        $old = $this->json($legacy['diffs']);
        $new = $this->json($row['diffs']);
        $this->assertIsArray($old);
        $this->assertIsArray($new);

        switch ($legacy['type']) {
            case 'insert':
            case 'update':
                $this->assertArrayHasKey('source', $new, $where);
                if (isset($old['@source'])) {
                    $this->assertSame($old['@source'], $new['source'], $where.': source');
                }

                unset($old['@source']);
                $this->assertSame(array_keys($old), array_keys($new['changes']), $where.': changed fields');

                $deepDiffs = array_keys(array_filter($old, self::isExpectedDeepDiff(...), ARRAY_FILTER_USE_BOTH));
                $this->assertSame($deepDiffs, $new['deep_diffs'] ?? [], $where.': deep_diffs');

                foreach ($old as $field => $diff) {
                    $isDeep = \in_array($field, $deepDiffs, true);
                    if ($isDeep) {
                        $this->assertSame($diff, $new['changes'][$field], \sprintf('%s: deep diff of %s', $where, $field));
                    } else {
                        $this->assertSame(['new' => $diff['new'] ?? null, 'old' => $diff['old'] ?? null], $new['changes'][$field], \sprintf('%s: %s', $where, $field));
                    }
                }

                break;

            case 'remove':
                $this->assertSame(['source' => $old, 'changes' => []], $new, $where);

                break;

            default: // associate, dissociate
                if (\array_key_exists('table', $old)) {
                    $old['join_table'] = $old['table'];
                    unset($old['table']);
                }

                $this->assertSame($this->sorted($old), $this->sorted($new), $where);
        }
    }

    /**
     * Since auditor 3.2.0, 1.x writes a deep diff for a JSON field when both values are arrays: a tree whose leaves are
     * {old, new}, holding at least one change. Otherwise, and for every change before 3.2.0, a field diff holding the
     * values (which may also be arrays).
     */
    private static function isExpectedDeepDiff(array $diff, string $field): bool
    {
        if (!\in_array($field, self::JSON_FIELDS, true)) {
            return false;
        }

        if ([] !== array_diff(array_keys($diff), ['new', 'old'])) {
            return true;
        }

        return self::isDeepDiffTree($diff) && (!\array_key_exists('new', $diff) || !\array_key_exists('old', $diff) || self::holdsAChange($diff));
    }

    /**
     * A tree of {old, new} leaves; 1.x writes an empty JSON array or object as is.
     */
    private static function isDeepDiffTree(mixed $node): bool
    {
        if (!\is_array($node) || [] === $node) {
            return false;
        }

        foreach ($node as $child) {
            $isLeaf = \is_array($child) && [] === array_diff(array_keys($child), ['new', 'old']);
            if (!$isLeaf && !self::isDeepDiffTree($child)) {
                return false;
            }
        }

        return true;
    }

    private static function holdsAChange(array $node): bool
    {
        return array_any($node, static fn ($child): bool => \is_array($child) && [] !== $child && ([] === array_diff(array_keys($child), ['new', 'old']) || self::holdsAChange($child)));
    }

    /**
     * JSON fields of a legacy entry that are deep diffs with only "new" and "old" keys: the history API cannot tell
     * them from field diffs in an unconverted entry. (Field diffs written before auditor 3.2.0 are not among them: they
     * must be read the same way before and after the conversion.).
     *
     * @param array<string, mixed> $legacy
     *
     * @return list<string>
     */
    private function ambiguousJsonFields(array $legacy): array
    {
        $diffs = $this->json($legacy['diffs']);
        if (!\is_array($diffs) || !\in_array($legacy['type'], ['insert', 'update'], true)) {
            return [];
        }

        return array_values(array_filter(
            self::JSON_FIELDS,
            static fn (string $field): bool => \is_array($diffs[$field] ?? null)
                && [] === array_diff(array_keys($diffs[$field]), ['new', 'old'])
                && self::isExpectedDeepDiff($diffs[$field], $field),
        ));
    }

    /**
     * The 2.x reader returns every migrated entry.
     */
    private function assertReadable(): void
    {
        $reader = new Reader($this->provider);
        $idsByTable = [];
        foreach (array_keys(Environment::entities()) as $entity) {
            $table = $reader->getEntityAuditTableName($entity);
            foreach ($reader->createQuery($entity, ['page_size' => null])->execute() as $entry) {
                $idsByTable[$table][] = (string) $entry->id;
            }
        }

        foreach ($this->snapshot as $tables) {
            foreach ($tables as $table => $legacyRows) {
                $expected = array_map(static fn (array $row): string => (string) $row['id'], $legacyRows);
                $actual = array_values(array_intersect(array_unique($idsByTable[$table] ?? []), $expected));
                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual, $table.': entries not returned by the reader.');
            }
        }

        // a migrated transaction is read back across tables and storages
        $sample = $this->snapshot[Environment::PRIMARY]['e2e_post_audit'][0];
        $ulid = $this->connection(Environment::PRIMARY)->fetchOne('SELECT transaction_id FROM e2e_post_audit WHERE id = ?', [$sample['id']]);
        $this->assertIsString($ulid);
        $expected = 0;
        foreach ($this->snapshot as $tables) {
            foreach ($tables as $legacyRows) {
                $expected += \count(array_filter($legacyRows, static fn (array $row): bool => $row['transaction_hash'] === $sample['transaction_hash']));
            }
        }

        $this->assertSame($expected, array_sum(array_map(count(...), $reader->getAuditsByTransactionId($ulid))), 'entries of a migrated transaction');
    }

    /**
     * audit:roots:rebuild indexes the migrated entries: none is skipped as v1 or unreadable.
     */
    private function assertRootIndexRebuilt(): void
    {
        $rebuilder = new RootIndexRebuilder($this->provider);
        $rows = 0;
        foreach ($rebuilder->tables() as $auditTable => $table) {
            $result = $rebuilder->rebuild($auditTable, $table);
            $this->assertSame(0, $result['legacy'], $auditTable.': entries skipped as v1');
            $this->assertSame(0, $result['unreadable'], $auditTable.': unreadable entries');
            $rows += $result['rows'];
        }

        $this->assertGreaterThan(0, $rows, 'The root index must have been filled from the migrated entries.');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function migrate(array $options): int
    {
        $command = new MigrateSchemaCommand();
        $command->setAuditor($this->provider->getAuditor());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute($options);
        if (false !== getenv('E2E_DEBUG')) {
            fwrite(STDERR, $tester->getDisplay());
        }

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function updateSchema(array $options): int
    {
        $tester = $this->updateSchemaTester($options);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getStatusCode();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function updateSchemaTester(array $options): CommandTester
    {
        $command = new UpdateSchemaCommand();
        $command->setAuditor($this->provider->getAuditor());

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /**
     * Has provider 1.x write the audit entries and reads its snapshot.
     */
    private function generateV1Data(): void
    {
        $directory = \dirname(__DIR__, 2).'/tools/migration-e2e/v1';
        if (!is_file($directory.'/vendor/autoload.php')) {
            $this->fail(\sprintf('Provider 1.x is not installed: run composer install --working-dir=%s', $directory));
        }

        exec(\sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($directory.'/generate.php')), $output, $exitCode);
        $this->assertSame(0, $exitCode, "The 1.x generator failed:\n".implode("\n", $output));

        $snapshot = json_decode((string) file_get_contents(Environment::snapshotPath()), true, 512, JSON_THROW_ON_ERROR);
        $this->snapshot = $snapshot['storages'];

        // the scenario must have produced every kind of entry
        $types = [];
        foreach ($this->snapshot as $tables) {
            foreach ($tables as $rows) {
                foreach ($rows as $row) {
                    $types[$row['type']] = true;
                }
            }
        }

        ksort($types);
        $this->assertSame(['associate', 'dissociate', 'insert', 'remove', 'update'], array_keys($types));
        $this->assertGreaterThan(1_000, \count($this->snapshot[Environment::PRIMARY]['e2e_translation_audit']));
    }

    private function createProvider(): DoctrineProvider
    {
        MoneyType::register();

        foreach ([Environment::PRIMARY, Environment::SECONDARY] as $storage) {
            $dbal = new DbalConfiguration();
            $dbal->setMiddlewares([new AuditorMiddleware(), new FailingStatementMiddleware()]);
            $orm = ORMSetup::createAttributeMetadataConfig([Environment::entityPath()], true);
            $orm->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
            $orm->enableNativeLazyObjects(true);
            $this->entityManagers[$storage] = new EntityManager(DriverManager::getConnection(Environment::connectionParameters($storage), $dbal), $orm);
        }

        $entities = Environment::entities();
        $entities[Comment::class]['roots'] = ['post'];
        $entities[Post::class]['roots'] = ['author'];

        $auditor = new Auditor(new AuditorConfiguration(['timezone' => 'UTC', 'enabled' => true]), new EventDispatcher());
        $provider = new DoctrineProvider(new Configuration([
            'table_prefix' => '',
            'table_suffix' => '_audit',
            'ignored_columns' => [],
            'entities' => $entities,
            'viewer' => false,
        ]));
        $provider->registerStorageService(new StorageService(Environment::PRIMARY, $this->entityManagers[Environment::PRIMARY]));
        $provider->registerStorageService(new StorageService(Environment::SECONDARY, $this->entityManagers[Environment::SECONDARY]));
        $provider->registerAuditingService(new AuditingService('default', $this->entityManagers[Environment::PRIMARY]));

        $auditor->registerProvider($provider);
        $provider->getConfiguration()->setStorageMapper(
            static fn (string $entity, array $storageServices): StorageServiceInterface => $storageServices[
                \in_array($entity, Environment::secondaryEntities(), true) ? Environment::SECONDARY : Environment::PRIMARY
            ]
        );

        return $provider;
    }

    private function connection(string $storage): Connection
    {
        return $this->entityManagers[$storage]->getConnection();
    }

    /**
     * @return iterable<array{Connection, string}>
     */
    private function auditTables(): iterable
    {
        foreach ($this->snapshot as $storage => $tables) {
            foreach (array_keys($tables) as $table) {
                yield [$this->connection($storage), $table];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function columns(Connection $connection, string $table): array
    {
        return array_map(
            static fn ($column): string => mb_strtolower($column->getObjectName()->getIdentifier()->getValue()),
            $connection->createSchemaManager()->introspectTableByUnquotedName($table)->getColumns(),
        );
    }

    /**
     * Schema and content of every table of both storages.
     */
    private function databaseState(): string
    {
        $state = [];
        foreach ($this->entityManagers as $storage => $entityManager) {
            $connection = $entityManager->getConnection();
            foreach ($connection->createSchemaManager()->introspectTableNames() as $name) {
                $table = $name->getUnqualifiedName()->getValue();
                $state[$storage][$table] = [
                    'columns' => $this->columns($connection, $table),
                    'rows' => $connection->fetchAllAssociative(\sprintf('SELECT * FROM %s', $table)),
                ];
            }

            ksort($state[$storage]);
        }

        return serialize($state);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changes(ChangeExtractor $extractor, Entry $entry): array
    {
        return array_map(static fn (Change $change): array => [
            'kind' => $change->kind->value,
            'entity' => $change->entity,
            'object_id' => $change->objectId,
            'field' => $change->field,
            'old' => $change->old,
            'new' => $change->new,
            'old_label' => $change->oldLabel,
            'new_label' => $change->newLabel,
            'labels' => $change->labels,
        ], $extractor->extract($entry));
    }

    /**
     * Hydrates an audit row as the reader does.
     *
     * @param array<string, mixed> $row
     */
    private function entry(array $row): Entry
    {
        $row['created_at'] = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));

        return Entry::fromArray($row);
    }

    private function scalar(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    private function timestamp(mixed $value): string
    {
        return new \DateTimeImmutable((string) $value)->format('Y-m-d H:i:s.u');
    }

    private function json(mixed $value): mixed
    {
        return null === $value ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function sorted(mixed $value): mixed
    {
        if (\is_array($value) && !array_is_list($value)) {
            ksort($value);
        }

        return \is_array($value) ? array_map($this->sorted(...), $value) : $value;
    }
}
