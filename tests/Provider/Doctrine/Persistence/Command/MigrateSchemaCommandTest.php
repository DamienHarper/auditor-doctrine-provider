<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
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
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
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
        $this->assertSame(Command::FAILURE, $commandTester->execute([]), 'A run that did nothing must not report a success.');

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
        // Existing rows are marked as legacy rows by the statement that adds the column.
        $this->assertMatchesRegularExpression('/schema_version SMALLINT[^;]*DEFAULT 1\b/i', $output);
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
        $this->assertStringContainsString('Step done. The migration is not complete', $output);
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
     * Before auditor 3.2.0, a JSON field got a field diff holding both whole values, never a deep diff: it stays a
     * field diff, even though both of its sides are arrays.
     */
    public function testConvertDiffsKeepsAJsonFieldDiffWrittenBeforeAuditor32(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'update', '90', [
            'json_array' => ['old' => ['theme' => 'dark', 'list' => [1, 2]], 'new' => ['theme' => 'light', 'list' => [1, 2, 3]]],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'json_array' => ['new' => ['theme' => 'light', 'list' => [1, 2, 3]], 'old' => ['theme' => 'dark', 'list' => [1, 2]]],
            ],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * A field diff written before auditor 3.2.0 whose values only hold empty arrays ([] to {"filters": []}) is not a
     * deep diff: a deep diff with both sides holds at least one change.
     */
    public function testConvertDiffsKeepsAFieldDiffOfEmptyArraysWrittenBeforeAuditor32(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'update', '90', [
            'json_array' => ['new' => ['filters' => []], 'old' => []],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'json_array' => ['new' => ['filters' => []], 'old' => []],
            ],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * {"new": []} inserted yields the deep diff {"new": []}: 1.x writes an empty JSON array as is.
     */
    public function testConvertDiffsKeepsADeepDiffHoldingAnEmptyArray(): void
    {
        $this->simulateLegacySchema('dummy_entity_audit');
        $id = $this->insertLegacyRow('dummy_entity_audit', 'insert', '90', [
            'json_array' => ['new' => []],
        ]);

        $this->executeConvertDiffs();

        $this->assertSameDiffs([
            'source' => ['id' => '90', 'class' => DummyEntity::class, 'label' => DummyEntity::class.'#90', 'table' => 'dummy_entity'],
            'changes' => [
                'json_array' => ['new' => []],
            ],
            'deep_diffs' => ['json_array'],
        ], $this->fetchConvertedDiffs('dummy_entity_audit', $id));
    }

    /**
     * A table already rid of its legacy columns but still holding unconverted diffs is converted along with the legacy
     * tables, not only once no legacy table is left.
     */
    public function testConvertDiffsAlsoConvertsTablesWithoutLegacyColumns(): void
    {
        $this->simulateLegacySchema('post_audit');
        $connection = $this->auditConnection();
        $connection->insert('comment_audit', [
            'schema_version' => 1,
            'type' => 'update',
            'object_id' => '1',
            'transaction_id' => '01HXYZ1234567890ABCDEFGHIJ',
            'diffs' => json_encode(['body' => ['old' => 'Old', 'new' => 'New']], JSON_THROW_ON_ERROR),
            'created_at' => '2024-01-01 00:00:00',
        ]);
        $id = (int) $connection->lastInsertId();

        $commandTester = new CommandTester($this->createCommand());
        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--force' => true, '--convert-all' => true]));

        $this->assertSameDiffs(['body' => ['new' => 'New', 'old' => 'Old']], $this->fetchConvertedDiffs('comment_audit', $id)['changes'] ?? null);
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
        $this->assertStringContainsString('Step done. The migration is not complete', $commandTester->getDisplay(true));

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

    /**
     * A row whose diffs cannot be converted without losing or misreading them is left as it is, reported, and
     * does not stop the conversion of the others.
     */
    public function testConvertDiffsLeavesUnconvertibleRowsAsTheyAre(): void
    {
        $this->simulateLegacySchema('post_audit');
        $connection = $this->auditConnection();
        $before = $this->insertLegacyRow('post_audit', 'update', '1', ['title' => ['old' => 'Old', 'new' => 'New']]);
        $scalar = $this->insertLegacyRow('post_audit', 'update', '2', []);
        $connection->update('post_audit', ['diffs' => '"a JSON string"'], ['id' => $scalar]);
        $fieldNotAnObject = $this->insertLegacyRow('post_audit', 'update', '3', ['title' => 'not a field diff']);
        $unknownType = $this->insertLegacyRow('post_audit', 'merge', '4', ['title' => ['new' => 'Merged']]);
        $unconvertible = [$scalar, $fieldNotAnObject, $unknownType];

        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // native JSON columns reject an invalid value on insert
            $invalid = $this->insertLegacyRow('post_audit', 'update', '5', []);
            $connection->update('post_audit', ['diffs' => '{not json'], ['id' => $invalid]);
            $unconvertible[] = $invalid;
        }

        $after = $this->insertLegacyRow('post_audit', 'update', '6', ['title' => ['old' => 'Old', 'new' => 'New']]);
        $legacyDiffs = array_map(static fn (int $id): mixed => $connection->fetchOne('SELECT diffs FROM post_audit WHERE id = ?', [$id]), $unconvertible);

        $commandTester = new CommandTester($this->createCommand());
        $exitCode = $commandTester->execute(['--force' => true, '--convert-all' => true]);
        $display = $commandTester->getDisplay(true);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('could not be converted', $display);
        $this->assertStringContainsString(implode(', ', $unconvertible), $display);
        foreach ($unconvertible as $i => $id) {
            $this->assertSame(1, (int) $connection->fetchOne('SELECT schema_version FROM post_audit WHERE id = ?', [$id]));
            $this->assertSame($legacyDiffs[$i], $connection->fetchOne('SELECT diffs FROM post_audit WHERE id = ?', [$id]), 'An unconvertible row must be left as it is.');
        }

        foreach ([$before, $after] as $id) {
            $this->assertSame(2, (int) $connection->fetchOne('SELECT schema_version FROM post_audit WHERE id = ?', [$id]));
        }

        // the check of the upgrade guide reports them
        $commandTester->execute([]);
        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('still have legacy diffs', $commandTester->getDisplay(true));
    }

    /**
     * --force alone keeps transaction_hash: it is only dropped once converted, or with --drop-transaction-hash.
     */
    public function testUnconvertedTransactionHashIsOnlyDroppedOnRequest(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);

        $commandTester = new CommandTester($this->createCommand());
        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--force' => true]));
        $this->assertTrue($this->auditTable('post_audit')->hasColumn('transaction_hash'), '--force alone must keep transaction_hash.');
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('blame_user'), 'The migrated blame columns are dropped.');
        $this->assertStringContainsString('transaction_hash has been kept', $commandTester->getDisplay(true));

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--force' => true, '--convert-all' => true, '--drop-transaction-hash' => true]), 'Converting and dropping without conversion contradict each other.');

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--force' => true, '--drop-transaction-hash' => true]));
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('transaction_hash'));
        $this->assertStringContainsString('dropped without conversion', $commandTester->getDisplay(true));
    }

    /**
     * After a run that keeps transaction_hash, the check without options must not report "nothing to migrate".
     */
    public function testCheckFailsWhileTransactionHashIsKept(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);

        $commandTester = new CommandTester($this->createCommand());
        foreach ([['--force' => true], ['--force' => true, '--convert-diffs' => true]] as $options) {
            $this->assertSame(Command::SUCCESS, $commandTester->execute($options));
            $this->assertStringContainsString('Step done. The migration is not complete', $commandTester->getDisplay(true));

            $this->assertSame(Command::FAILURE, $commandTester->execute([]), 'The check must fail while transaction_hash is kept.');
            $this->assertStringContainsString('still have transaction_hash', $commandTester->getDisplay(true));
        }
    }

    public function testLimitNeedsAConversionAndNoDrop(): void
    {
        $this->simulateLegacySchema('post_audit');

        foreach ([['--force' => true, '--limit' => '10'], ['--force' => true, '--convert-diffs' => true, '--drop-transaction-hash' => true, '--limit' => '10']] as $options) {
            $commandTester = new CommandTester($this->createCommand());
            $this->assertSame(Command::FAILURE, $commandTester->execute($options));
            $this->assertStringContainsString('--limit limits the conversions', $commandTester->getDisplay(true));
        }

        $this->assertFalse($this->auditTable('post_audit')->hasColumn('schema_version'), 'Nothing must have been migrated.');
    }

    /**
     * In a table being migrated, a row whose transaction_hash is not converted yet is found by that hash.
     */
    public function testUnconvertedTransactionIsFoundByItsHash(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->insertLegacyRow('post_audit', 'update', '1', ['title' => ['old' => 'a', 'new' => 'b']]);
        $this->insertLegacyRow('post_audit', 'update', '1', ['title' => ['old' => 'b', 'new' => 'c']]);

        $commandTester = new CommandTester($this->createCommand());
        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--force' => true, '--convert-diffs' => true]));
        $this->assertTrue($this->auditTable('post_audit')->hasColumn('transaction_hash'));

        $audits = new Reader($this->provider)->getAuditsByTransactionId(sha1('post_auditupdate1'));
        $this->assertCount(2, $audits[Post::class] ?? []);
    }

    /**
     * The converted diffs are escaped like the ones the provider writes: ASCII only, whatever the column charset.
     */
    public function testConvertedDiffsAreEscaped(): void
    {
        $platform = $this->auditConnection()->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform || ($platform instanceof MySQLPlatform && !$platform instanceof MariaDBPlatform)) {
            $this->markTestSkipped('A native JSON column returns the JSON it stores in its own form.');
        }

        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'update', '1', ['title' => ['old' => 'Zoë', 'new' => '🚀 中文']]);

        $this->executeConvertDiffs();

        $diffs = $this->auditConnection()->fetchOne('SELECT diffs FROM post_audit WHERE id = ?', [$id]);
        $this->assertIsString($diffs);
        $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $diffs);
        $this->assertSameDiffs(['title' => ['new' => '🚀 中文', 'old' => 'Zoë']], $this->fetchConvertedDiffs('post_audit', $id)['changes'] ?? null);
    }

    public function testLimitMustBeAPositiveInteger(): void
    {
        $this->simulateLegacySchema('post_audit');

        foreach (['0', '-5', 'abc', '1.5'] as $limit) {
            $commandTester = new CommandTester($this->createCommand());
            $this->assertSame(Command::FAILURE, $commandTester->execute(['--force' => true, '--convert-all' => true, '--limit' => $limit]), '--limit='.$limit);
            $this->assertStringContainsString('--limit must be a positive integer', $commandTester->getDisplay(true));
        }

        $this->assertTrue($this->auditTable('post_audit')->hasColumn('transaction_hash'), 'Nothing must have been migrated.');
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('schema_version'), 'Nothing must have been migrated.');
    }

    /**
     * A table that lost transaction_hash (to a Doctrine migration, say) but kept the legacy blame columns is still
     * a legacy table: the blame is migrated before the columns are dropped, and audit:schema:update refuses to run.
     */
    public function testTableWithLegacyBlameColumnsOnlyIsMigrated(): void
    {
        $this->simulateLegacySchema('post_audit');
        $connection = $this->auditConnection();
        $id = $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);
        $connection->update('post_audit', ['blame_user' => 'dark.vador', 'ip' => '1.2.3.4'], ['id' => $id]);
        foreach ($this->auditTable('post_audit')->getIndexes() as $index) {
            if (\in_array('transaction_hash', array_map(static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(), $index->getIndexedColumns()), true)) {
                $connection->executeStatement($connection->getDatabasePlatform()->getDropIndexSQL($index->getObjectName()->toSQL($connection->getDatabasePlatform()), 'post_audit'));
            }
        }

        $connection->executeStatement('ALTER TABLE post_audit DROP COLUMN transaction_hash');

        $updater = new SchemaManager($this->provider);
        $this->assertContains('post_audit', $updater->collectLegacyAuditTables(), 'audit:schema:update must refuse to run.');

        $commandTester = new CommandTester($this->createCommand());
        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--force' => true, '--convert-all' => true]));
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('blame_user'));
        $blame = json_decode((string) $connection->fetchOne('SELECT blame FROM post_audit WHERE id = ?', [$id]), true);
        $this->assertIsArray($blame);
        $this->assertSame('dark.vador', $blame['username']);
        $this->assertSame('1.2.3.4', $blame['ip']);
    }

    public function testLimitedTransactionHashConversionKeepsTheTransactionHashIndex(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->createLegacyTransactionHashIndex('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);
        $this->insertLegacyRow('post_audit', 'insert', '2', ['title' => ['new' => 'Second']]);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-transaction-hash' => true, '--limit' => '1']);

        $this->assertStringContainsString('Step done. The migration is not complete', $commandTester->getDisplay(true));
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

        $this->assertMatchesRegularExpression('/(\[OK\] Audit schema migration completed successfully!|Step done\. The migration is not complete)/', $commandTester->getDisplay());
        $this->assertFalse($this->hasLegacyTransactionHashIndex('post_audit'));
        $this->assertFalse($this->auditTable('post_audit')->hasColumn('transaction_hash'));
        $this->assertSame(0, (int) $this->auditConnection()->fetchOne('SELECT COUNT(*) FROM post_audit WHERE transaction_id IS NULL'));
    }

    public function testInterruptedTransactionHashConversionKeepsTheTransactionGroupedAcrossTables(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->simulateLegacySchema('comment_audit');
        $postRow = $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);
        $commentRow = $this->insertLegacyRow('comment_audit', 'insert', '1', ['body' => ['new' => 'Hello']]);

        // Both rows were written by the same flush; a previous run converted post_audit, then stopped.
        $connection = $this->auditConnection();
        $connection->executeStatement('ALTER TABLE post_audit ADD COLUMN transaction_id CHAR(26)');
        $connection->executeStatement('UPDATE comment_audit SET transaction_hash = ? WHERE id = ?', ['shared-hash', $commentRow]);
        $connection->executeStatement('UPDATE post_audit SET transaction_hash = ?, transaction_id = ? WHERE id = ?', ['shared-hash', '01JZZZZZZZZZZZZZZZZZZZZZZZ', $postRow]);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true, '--convert-transaction-hash' => true]);

        $this->assertMatchesRegularExpression('/(\[OK\] Audit schema migration completed successfully!|Step done\. The migration is not complete)/', $commandTester->getDisplay());
        $this->assertSame('01JZZZZZZZZZZZZZZZZZZZZZZZ', $connection->fetchOne('SELECT transaction_id FROM comment_audit WHERE id = ?', [$commentRow]));
    }

    public function testCompleteMigrationDropsTheLegacyBlameUserIndex(): void
    {
        $this->simulateLegacySchema('post_audit');
        $this->createLegacyBlameUserIndex('post_audit');
        $this->insertLegacyRow('post_audit', 'insert', '1', ['title' => ['new' => 'First']]);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--force' => true]);

        $this->assertStringContainsString('Step done. The migration is not complete', $commandTester->getDisplay(true));
        $table = $this->auditTable('post_audit');
        $this->assertFalse($table->hasColumn('blame_user'));
        $this->assertFalse($table->hasIndex($this->legacyBlameUserIndexName('post_audit')), 'The 1.x (blame_user, blame_id) index must not survive the migration.');

        // audit:schema:update then brings the table to the v2 indexes, and only those.
        new SchemaManager($this->provider)->updateAuditSchema();

        $expectedIndexes = array_map(
            static fn (array $struct): string => mb_strtolower($struct['name']),
            array_filter(SchemaHelper::getAuditTableIndices('post_audit'), static fn (array $struct): bool => isset($struct['name'])),
        );
        $actualIndexes = [];
        foreach ($this->auditTable('post_audit')->getIndexes() as $index) {
            $columns = array_map(static fn (IndexedColumn $column): string => $column->getColumnName()->toString(), $index->getIndexedColumns());
            if (['id'] !== $columns) { // primary key
                $actualIndexes[] = mb_strtolower($index->getObjectName()->toString());
            }
        }

        $this->assertEqualsCanonicalizing(array_values($expectedIndexes), $actualIndexes);
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
     * Creates the (blame_user, blame_id) index added to v1 audit tables by provider 1.x.
     */
    private function createLegacyBlameUserIndex(string $tableName): void
    {
        $this->auditConnection()->executeStatement(\sprintf(
            'CREATE INDEX %s ON %s (blame_user, blame_id)',
            $this->legacyBlameUserIndexName($tableName),
            $tableName,
        ));
    }

    private function legacyBlameUserIndexName(string $tableName): string
    {
        return 'blame_user_blame_id_'.md5($tableName).'_idx';
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

        $this->assertStringContainsString('Step done. The migration is not complete', $commandTester->getDisplay(true));
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
