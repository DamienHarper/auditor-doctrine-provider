<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
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

        $this->assertSame([
            'source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'changes' => [
                'title' => ['new' => 'First post', 'old' => null],
                'author_id' => ['new' => ['id' => 1, 'class' => Author::class, 'label' => 'John', 'table' => 'author'], 'old' => null],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsFallsBackWhenLegacyInsertHasNoSource(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'insert', '7', [
            'title' => ['new' => 'Untitled'],
        ]);

        $this->executeConvertDiffs();

        $this->assertSame([
            'source' => ['id' => '7', 'class' => '', 'label' => '', 'table' => ''],
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

        $this->assertSame([
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

        // MySQL and PostgreSQL JSON columns do not keep the order of object keys.
        $this->assertSame($this->sortKeys([
            'source' => ['id' => '90', 'class' => '', 'label' => '', 'table' => ''],
            'changes' => [
                'title' => ['new' => 'Second title', 'old' => 'First title'],
                'settings' => [
                    'theme' => ['new' => 'dark', 'old' => 'light'],
                    'lang' => ['old' => 'fr'],
                    'notifications' => ['email' => ['new' => true]],
                ],
            ],
            'deep_diffs' => ['settings'],
        ]), $this->sortKeys($this->fetchConvertedDiffs('post_audit', $id)));
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

        $this->assertSame([
            'source' => ['id' => '90', 'class' => '', 'label' => '', 'table' => ''],
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

        $this->assertSame([
            'source' => ['id' => '90', 'class' => '', 'label' => '', 'table' => ''],
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

        $this->assertSame([
            'source' => ['id' => '90', 'class' => '', 'label' => '', 'table' => ''],
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

        $this->assertSame([
            'source' => ['id' => '90', 'class' => '', 'label' => '', 'table' => ''],
            'changes' => [
                'author' => ['new' => $author, 'old' => null],
            ],
        ], $this->fetchConvertedDiffs('post_audit', $id));
    }

    public function testConvertDiffsKeepsLegacyRemoveSummaryAsSource(): void
    {
        $this->simulateLegacySchema('post_audit');
        $id = $this->insertLegacyRow('post_audit', 'remove', '90', [
            'id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post',
        ]);

        $this->executeConvertDiffs();

        $this->assertSame([
            'source' => ['id' => 90, 'class' => Post::class, 'label' => 'First post', 'table' => 'post'],
            'changes' => [],
        ], $this->fetchConvertedDiffs('post_audit', $id));
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
            $name = $table->getName();
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
     * @param array<string, mixed> $diffs legacy diffs payload
     */
    private function insertLegacyRow(string $tableName, string $type, string $objectId, array $diffs): int
    {
        $connection = $this->auditConnection();
        $connection->insert($tableName, [
            'type' => $type,
            'object_id' => $objectId,
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
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $array): array
    {
        ksort($array);

        return array_map(fn (mixed $value): mixed => \is_array($value) ? $this->sortKeys($value) : $value, $array);
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
