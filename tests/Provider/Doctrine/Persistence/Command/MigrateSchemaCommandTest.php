<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
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
        ]);
    }

    private function simulateLegacySchema(): void
    {
        $storageService = array_values($this->provider->getStorageServices())[0];
        $connection = $storageService->getEntityManager()->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        foreach ($schema->getTables() as $table) {
            $name = $table->getName();
            if (!str_ends_with($name, '_audit')) {
                continue;
            }

            if ($table->hasColumn('schema_version') && !$table->hasColumn('transaction_hash')) {
                // Drop all indexes first (required by SQLite before dropping indexed columns)
                foreach ($table->getIndexes() as $index) {
                    if (!$index->isPrimary()) {
                        $connection->executeStatement(\sprintf('DROP INDEX IF EXISTS %s', $index->getName()));
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

    private function createCommand(): MigrateSchemaCommand
    {
        $command = new MigrateSchemaCommand();
        $command->setAuditor($this->provider->getAuditor());

        return $command;
    }
}
