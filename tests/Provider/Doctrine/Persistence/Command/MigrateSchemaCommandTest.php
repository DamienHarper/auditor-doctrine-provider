<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Bike;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Vehicle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[Small]
final class MigrateSchemaCommandTest extends TestCase
{
    use LockableTrait;
    use SchemaSetupTrait;

    protected function setUp(): void
    {
        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();

        // declare audited entities
        $this->configureEntities();

        // setup entity schema + audit tables (v2 format)
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
        $this->setupEntities();
    }

    public function testExecuteNothingToMigrateWhenAlreadyAtV2(): void
    {
        // Audit tables were created with the current SchemaHelper (v2 format),
        // so the migration command should find nothing to do.
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('All audit tables are already at schema version 2', $output);
    }

    #[Depends('testExecuteNothingToMigrateWhenAlreadyAtV2')]
    public function testExecuteFailsWhileLocked(): void
    {
        $this->lock('audit:schema:migrate');

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('The command is already running in another process.', $output);

        $this->release();
    }

    #[Depends('testExecuteNothingToMigrateWhenAlreadyAtV2')]
    public function testExecuteWithLegacySchemaDumpsSql(): void
    {
        // Simulate a v1 table by removing schema_version and adding transaction_hash
        $this->simulateLegacySchema();

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--dump-sql' => true]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('The following SQL statements will be executed:', $output);
        $this->assertStringContainsString('schema_version = 1', $output);
    }

    #[Depends('testExecuteWithLegacySchemaDumpsSql')]
    public function testExecuteWithLegacySchemaAndForce(): void
    {
        // Simulate a v1 table by removing schema_version and adding transaction_hash
        $this->simulateLegacySchema();

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--force' => true]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Audit schema migration completed successfully!', $output);
    }

    #[Depends('testExecuteWithLegacySchemaAndForce')]
    public function testExecuteWithLegacySchemaWithoutForcePrintsCaution(): void
    {
        // Simulate a v1 table by removing schema_version and adding transaction_hash
        $this->simulateLegacySchema();

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('--dump-sql', $output);
    }

    public function testConvertDiffsReusesLegacyInsertSource(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'insert', '90', [
            '@source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'title' => ['new' => 'First post'],
            'author_id' => ['new' => ['id' => 1, 'class' => Author::class, 'label' => 'John', 'table' => 'author']],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'First post', 'old' => null],
                'author_id' => ['new' => ['id' => 1, 'class' => Author::class, 'label' => 'John', 'table' => 'author'], 'old' => null],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsBuildsSourceFromEntityWhenLegacyInsertHasNoSource(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'insert', '7', [
            'title' => ['new' => 'Untitled'],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '7', 'class' => Post::class, 'label' => Post::class.'#7', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'Untitled', 'old' => null],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsKeepsLegacyInsertJsonDeepDiff(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'insert', '90', [
            '@source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'title' => ['new' => 'First post'],
            'settings' => [
                'theme' => ['new' => 'dark'],
                'notifications' => ['email' => ['new' => true]],
            ],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'First post', 'old' => null],
                'settings' => [
                    'theme' => ['new' => 'dark'],
                    'notifications' => ['email' => ['new' => true]],
                ],
            ],
            'deep_diffs' => ['settings'],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsKeepsLegacyUpdateJsonDeepDiff(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'update', '90', [
            'title' => ['new' => 'Second title', 'old' => 'First title'],
            'settings' => [
                'theme' => ['new' => 'dark', 'old' => 'light'],
                'lang' => ['old' => 'fr'],
                'notifications' => ['email' => ['new' => true]],
            ],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => Post::class, 'label' => Post::class.'#90', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'Second title', 'old' => 'First title'],
                'settings' => [
                    'theme' => ['new' => 'dark', 'old' => 'light'],
                    'lang' => ['old' => 'fr'],
                    'notifications' => ['email' => ['new' => true]],
                ],
            ],
            'deep_diffs' => ['settings'],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    /**
     * A JSON value whose root only has a "new" key ({"new": "dark"}) yields the deep diff
     * {"new": {"new": "dark"}}, shaped like the field diff of an association ({"new": summary}).
     * The entity metadata tells them apart.
     */
    public function testConvertDiffsKeepsLegacyInsertJsonDeepDiffWithNewKeyAtRoot(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'insert', '90', [
            'label' => ['new' => 'label'],
            'json_array' => ['new' => ['new' => 'dark']],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'label' => ['new' => 'label', 'old' => null],
                'json_array' => ['new' => ['new' => 'dark']],
            ],
            'deep_diffs' => ['json_array'],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * {"new": 1} updated to {"new": 2} yields the deep diff {"new": {"new": 2, "old": 1}}.
     */
    public function testConvertDiffsKeepsLegacyUpdateJsonDeepDiffWithNewKeyAtRoot(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'update', '90', [
            'json_array' => ['new' => ['new' => 2, 'old' => 1]],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'json_array' => ['new' => ['new' => 2, 'old' => 1]],
            ],
            'deep_diffs' => ['json_array'],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * A JSON field whose value went from a scalar to an array got a field diff: it stays one.
     */
    public function testConvertDiffsNormalizesLegacyJsonFieldDiff(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'update', '90', [
            'json_array' => ['old' => 'scalar', 'new' => ['new' => 1]],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'json_array' => ['new' => ['new' => 1], 'old' => 'scalar'],
            ],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * The field diff of an association ({"new": summary}) only holds arrays: it stays a field diff.
     */
    public function testConvertDiffsNormalizesLegacyAssociationFieldDiff(): void
    {
        $this->simulateLegacySchema('post_audit');
        $author = ['id' => 1, 'class' => Author::class, 'label' => 'An author', 'table' => 'author'];
        $id = $this->insertLegacyRow('post_audit', 'update', '90', [
            'author' => ['new' => $author],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => Post::class, 'label' => Post::class.'#90', 'table' => 'post'],
            'changes' => [
                'author' => ['new' => $author, 'old' => null],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsBuildsSourceFromEntityForLegacyUpdate(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'update', '12', [
            'title' => ['old' => 'Draft', 'new' => 'Final'],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '12', 'class' => Post::class, 'label' => Post::class.'#12', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'Final', 'old' => 'Draft'],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsUsesDiscriminatorAsClassForSingleTableInheritance(): void
    {
        $this->simulateLegacySchema('vehicle_audit');
        $id = $this->insertLegacyRow('vehicle_audit', 'update', '3', [
            'wheels' => ['old' => 4, 'new' => 3],
        ], Car::class);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '3', 'class' => Car::class, 'label' => Car::class.'#3', 'table' => 'vehicle'],
            'changes' => [
                'wheels' => ['new' => 3, 'old' => 4],
            ],
        ], $this->fetchConvertedDiffs('vehicle_audit', $id));
    }

    public function testConvertDiffsLeavesClassEmptyWhenSeveralEntitiesShareTheAuditTable(): void
    {
        $this->simulateLegacySchema('vehicle_audit');
        $id = $this->insertLegacyRow('vehicle_audit', 'update', '3', [
            'wheels' => ['old' => 4, 'new' => 3],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '3', 'class' => '', 'label' => '', 'table' => 'vehicle'],
            'changes' => [
                'wheels' => ['new' => 3, 'old' => 4],
            ],
        ], $this->fetchConvertedDiffs('vehicle_audit', $id));
    }

    public function testConvertDiffsKeepsLegacyRemoveSummaryAsSource(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'remove', '90', [
            'id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post',
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'changes' => [],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsBuildsSourceFromEntityWhenLegacyRemoveIsEmpty(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'remove', '90', []);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => Post::class, 'label' => Post::class.'#90', 'table' => 'post'],
            'changes' => [],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsConvertsEveryRowAcrossBatchesAndResumes(): void
    {
        // More rows than one batch (500), converted by a limited run ending in the middle of the second
        // batch, then by a run that resumes after the rows already converted.
        $this->simulateLegacySchema('post_audit');
        $ids = [];
        for ($i = 1; $i <= 1_201; ++$i) {
            $ids[] = $this->insertLegacyRow('post_audit', 'update', (string) $i, ['title' => ['old' => 'Old '.$i, 'new' => 'New '.$i]]);
        }

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-diffs' => true, '--limit' => '700']);
        $this->assertStringContainsString('[OK] Audit schema migration completed successfully!', $commandTester->getDisplay());

        $connection = $this->auditConnection();
        $this->assertSame(
            \array_slice($ids, 0, 700),
            array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM post_audit WHERE schema_version = 2 ORDER BY id')),
        );

        $this->executeConvertDiffs();

        $this->assertSame(1_201, (int) $connection->fetchOne('SELECT COUNT(*) FROM post_audit WHERE schema_version = 2'));
        foreach ([0, 499, 500, 699, 700, 1_200] as $index) {
            $number = $index + 1;
            $changes = $this->fetchConvertedDiffs('post_audit', $ids[$index])['changes'] ?? null;
            $this->assertIsArray($changes);
            $this->assertSameDiffs(['title' => ['new' => 'New '.$number, 'old' => 'Old '.$number]], $changes);
        }
    }

    public function testLimitedTransactionHashConversionKeepsTheTransactionHashIndex(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->createLegacyTransactionHashIndex('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);
        $this->insertLegacyRow('post_audit', 'insert', '2', ['title' => ['new' => 'Second']]);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-transaction-hash' => true, '--limit' => '1']);

        $this->assertStringContainsString('[OK] Audit schema migration completed successfully!', $commandTester->getDisplay());
        $this->assertTrue($this->hasLegacyTransactionHashIndex('post_audit'), 'The conversion must still find rows by transaction_hash through its index.');
        $this->assertSame(1, (int) $this->auditConnection()->fetchOne('SELECT COUNT(*) FROM post_audit WHERE transaction_id IS NOT NULL'));
    }

    public function testDiffsConversionAloneKeepsTheTransactionHashIndex(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->createLegacyTransactionHashIndex('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);

        $this->executeConvertDiffs();

        $this->assertTrue($this->auditTable('post_audit')->hasColumn('transaction_hash'));
        $this->assertTrue($this->hasLegacyTransactionHashIndex('post_audit'), 'A later --convert-transaction-hash run needs the transaction_hash index.');
    }

    public function testCompleteMigrationDropsTheTransactionHashIndexWithItsColumn(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->createLegacyTransactionHashIndex('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);
        $this->insertLegacyRow('post_audit', 'insert', '2', ['title' => ['new' => 'Second']]);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-transaction-hash' => true]);

        $this->assertStringContainsString('[OK] Audit schema migration completed successfully!', $commandTester->getDisplay());
        $this->assertFalse($this->hasLegacyTransactionHashIndex('post_audit'));
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('transaction_hash'));
        $this->assertSame(0, (int) $this->auditConnection()->fetchOne('SELECT COUNT(*) FROM post_audit WHERE transaction_id IS NULL'));
    }

    /**
     * Manipulates the first audit table to look like a v1 schema:
     * drops schema_version, adds transaction_hash (VARCHAR 40).
     */
    protected function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
            DummyEntity::class => ['enabled' => true],
            Vehicle::class => ['enabled' => true],
            Car::class => ['enabled' => true],
            Bike::class => ['enabled' => true],
        ]);
    }

    /**
     * @param ?string $tableName Audit table to downgrade (null = the first audit table found)
     */
    private function simulateLegacySchema(?string $tableName = null): void
    {
        $connection = $this->auditConnection();
        $schemaManager = $connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        foreach ($schema->getTables() as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (!str_ends_with((string) $name, '_audit')) {
                continue;
            }

            if (null !== $tableName && $tableName !== (string) $name) {
                continue;
            }

            if ($table->hasColumn('schema_version') && !$table->hasColumn('transaction_hash')) {
                // Drop all indexes first (required by SQLite before dropping indexed columns)
                $platform = $connection->getDatabasePlatform();
                foreach (SchemaHelper::getAuditTableIndices((string) $name) as $struct) {
                    if (!isset($struct['name']) || !$table->hasIndex($struct['name'])) {
                        continue; // primary key
                    }

                    // MySQL/MariaDB require ON <table>; no IF EXISTS support for DROP INDEX
                    if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
                        $connection->executeStatement(\sprintf('DROP INDEX %s ON %s', $struct['name'], $name));
                    } else {
                        $connection->executeStatement(\sprintf('DROP INDEX IF EXISTS %s', $struct['name']));
                    }
                }

                // Simulate a v1 schema: drop v2 columns, add v1 columns
                $connection->executeStatement(\sprintf('ALTER TABLE %s DROP COLUMN schema_version', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s DROP COLUMN transaction_id', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s DROP COLUMN blame', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN transaction_hash VARCHAR(40)', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN blame_user VARCHAR(255)', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN blame_user_fqdn VARCHAR(255)', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN blame_user_firewall VARCHAR(255)', $name));
                $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN ip VARCHAR(255)', $name));

                return; // Only do this for the first audit table
            }
        }
    }

    /**
     * Inserts a row in the v1 format into a table downgraded by simulateLegacySchema().
     *
     * @param array<string, mixed> $diffs         legacy diffs payload
     * @param ?string              $discriminator class of the audited entity (single table inheritance only)
     */
    private function insertLegacyRow(string $tableName, string $type, string $objectId, array $diffs, ?string $discriminator = null): int
    {
        $connection = $this->auditConnection();
        $connection->insert($tableName, [
            'type' => $type,
            'object_id' => $objectId,
            'discriminator' => $discriminator,
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'transaction_hash' => sha1($tableName.$type.$objectId),
            'created_at' => '2024-01-01 00:00:00',
        ]);

        return (int) $connection->lastInsertId();
    }

    private function executeConvertDiffs(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-diffs' => true]);

        $this->assertStringContainsString('[OK] Audit schema migration completed successfully!', $commandTester->getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchConvertedDiffs(string $tableName, int $id): array
    {
        $row = $this->auditConnection()->fetchAssociative(
            \sprintf('SELECT schema_version, diffs FROM %s WHERE id = ?', $tableName),
            [$id],
        );

        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['schema_version']);

        $diffs = json_decode((string) $row['diffs'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($diffs);

        /** @var array<string, mixed> $diffs */
        return $diffs;
    }

    /**
     * Compares converted diffs regardless of key order: JSON columns on MySQL and PostgreSQL (jsonb)
     * do not preserve the order of object keys.
     *
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private function assertSameDiffs(array $expected, array $actual): void
    {
        $this->assertSame($this->sortKeysRecursively($expected), $this->sortKeysRecursively($actual));
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private function sortKeysRecursively(array $array): array
    {
        ksort($array);

        return array_map(fn (mixed $value): mixed => \is_array($value) ? $this->sortKeysRecursively($value) : $value, $array);
    }

    /**
     * Creates the transaction_hash index of a v1 audit table, which simulateLegacySchema() leaves out.
     */
    private function createLegacyTransactionHashIndex(string $tableName): void
    {
        $this->auditConnection()->executeStatement(\sprintf(
            'CREATE INDEX %s ON %s (transaction_hash)',
            $this->legacyTransactionHashIndexName($tableName),
            $tableName,
        ));
    }

    private function hasLegacyTransactionHashIndex(string $tableName): bool
    {
        return $this->auditTable($tableName)->hasIndex($this->legacyTransactionHashIndexName($tableName));
    }

    private function legacyTransactionHashIndexName(string $tableName): string
    {
        return 'transaction_hash_'.md5($tableName).'_idx';
    }

    private function auditTable(string $tableName): Table
    {
        return $this->auditConnection()->createSchemaManager()->introspectSchema()->getTable($tableName);
    }

    private function auditConnection(): Connection
    {
        $storageService = array_values($this->provider->getStorageServices())[0];

        return $storageService->getEntityManager()->getConnection();
    }

    private function createCommand(): MigrateSchemaCommand
    {
        $command = new MigrateSchemaCommand();
        $command->setAuditor($this->provider->getAuditor());

        return $command;
    }
}
