<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Retention\RetentionPruner;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Animal;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Cat;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Dog;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
final class CleanAuditLogsCommandTest extends TestCase
{
    use LockableTrait;
    use SchemaSetupTrait;

    private const string KEEP = 'WRONG';

    public function testExecuteFailsWithKeepWrongFormat(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
            'keep' => self::KEEP,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString(\sprintf("[ERROR] 'keep' argument must be a valid ISO 8601 date interval, '%s' given.", self::KEEP), $output);
    }

    public function testExecuteFailsWithInvalidDateOption(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
            '--date' => 'not-a-date',
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
    }

    public function testExecuteFailsWithInvalidPerEntityMaxAge(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_age' => 'NOT_AN_INTERVAL'],
        ]);

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--no-confirm' => true]);

        $output = $commandTester->getDisplay();
        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid retention "maxAge" value', $output);
        $this->assertStringContainsString('NOT_AN_INTERVAL', $output);
    }

    public function testDumpSQL(): void
    {
        $schemaManager = new SchemaManager($this->provider);

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $entities = $configuration->getEntities();

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
            '--dump-sql' => true,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();

        foreach (array_keys($entities) as $entity) {
            $expected = 'DELETE FROM '.$schemaManager->resolveAuditTableName($entity, $configuration);
            $this->assertStringContainsString($expected, $output);
        }

        // No max_entries is configured in configureEntities() — no count-based DELETE expected
        $this->assertStringNotContainsString('created_at = ', $output);

        $this->assertStringContainsString('[OK] Success', $output);
    }

    #[Depends('testDumpSQL')]
    public function testExecute(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Success', $output);
    }

    #[Depends('testExecute')]
    public function testExecuteFailsWhileLocked(): void
    {
        $this->lock('audit:clean');

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('The command is already running in another process.', $output);

        $this->release();
    }

    public function testDateOption(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--date' => '2023-04-26T09:00:00Z',
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('clean audits created before 2023-04-26 09:00:00', $output);
    }

    public function testExcludeOptionSingleValue(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--exclude' => Author::class,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('6 classes', $output);
    }

    public function testExcludeOptionMultipleValues(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--exclude' => [
                Author::class,
                Post::class,
            ],
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('5 classes', $output);
    }

    public function testIncludeOptionSignleValue(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--include' => Author::class,
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 classes', $output);
    }

    public function testIncludeOptionMultipleValues(): void
    {
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--include' => [
                Author::class,
                Post::class,
            ],
        ]);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('2 classes', $output);
    }

    public function testPerEntityMaxAgeOverridesGlobalKeep(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_age' => 'P1D'],
            Post::class => ['enabled' => true],
        ]);

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
            '--dump-sql' => true,
            'keep' => 'P12M',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Success', $output);

        $schemaManager = new SchemaManager($this->provider);
        $configuration = $this->provider->getConfiguration();
        $authorTable = $schemaManager->resolveAuditTableName(Author::class, $configuration);
        $postTable = $schemaManager->resolveAuditTableName(Post::class, $configuration);

        // Extract the DELETE cutoff date for Author (per-entity P1D)
        $this->assertMatchesRegularExpression(
            '/DELETE FROM '.preg_quote((string) $authorTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output
        );
        preg_match(
            '/DELETE FROM '.preg_quote((string) $authorTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output,
            $authorMatches
        );

        // Extract the DELETE cutoff date for Post (global P12M)
        $this->assertMatchesRegularExpression(
            '/DELETE FROM '.preg_quote((string) $postTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output
        );
        preg_match(
            '/DELETE FROM '.preg_quote((string) $postTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output,
            $postMatches
        );

        // Author cutoff (P1D ago) must be more recent than Post cutoff (P12M ago)
        $authorCutoff = new \DateTimeImmutable($authorMatches[1]);
        $postCutoff = new \DateTimeImmutable($postMatches[1]);
        $this->assertGreaterThan($postCutoff, $authorCutoff, 'Per-entity max_age P1D must yield a more recent cutoff than global P12M');
    }

    public function testMaxEntriesKeepsTheMostRecentEntries(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_entries' => 2],
            Post::class => ['enabled' => true],
        ]);
        $this->insertEntries(Author::class, ['a' => 'P3D', 'b' => 'P2D', 'c' => 'P1D']);
        $this->insertEntries(Post::class, ['x' => 'P3D', 'y' => 'P2D', 'z' => 'P1D']);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--no-confirm' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Per-entity retention policies', $commandTester->getDisplay());
        $this->assertSame(['b', 'c'], $this->remainingEntries(Author::class));
        $this->assertSame(['x', 'y', 'z'], $this->remainingEntries(Post::class));
    }

    public function testDryRunKeepsEveryEntry(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_age' => 'P1D', 'max_entries' => 1],
        ]);
        $this->insertEntries(Author::class, ['a' => 'P3D', 'b' => 'P2D', 'c' => 'PT1M', 'd' => 'PT1M']);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--no-confirm' => true, '--dry-run' => true, '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('created_at = ', $commandTester->getDisplay());
        $this->assertSame(['a', 'b', 'c', 'd'], $this->remainingEntries(Author::class));
    }

    public function testAnInvalidPolicyFailsBeforeAnyDeletion(): void
    {
        // Author comes first and has a valid policy; Post has an invalid one.
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_entries' => 1],
            Post::class => ['enabled' => true, 'max_entries' => 0],
        ]);
        $this->insertEntries(Author::class, ['a' => 'P2Y', 'b' => 'P1D']);
        $this->insertEntries(Post::class, ['x' => 'P2Y']);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--no-confirm' => true]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid retention "maxEntries" value 0', $commandTester->getDisplay());
        $this->assertSame(['a', 'b'], $this->remainingEntries(Author::class));
        $this->assertSame(['x'], $this->remainingEntries(Post::class));
    }

    public function testEntitiesWithoutPolicyKeepTheGlobalBehavior(): void
    {
        $this->insertEntries(Post::class, ['old' => 'P13M', 'recent' => 'P11M']);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--no-confirm' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringNotContainsString('Per-entity retention policies', $commandTester->getDisplay());
        $this->assertSame(['recent'], $this->remainingEntries(Post::class));
    }

    public function testExcludedEntitiesAreNotCleaned(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_entries' => 1],
            Post::class => ['enabled' => true],
        ]);
        $this->insertEntries(Author::class, ['a' => 'P2Y', 'b' => 'P1D']);
        $this->insertEntries(Post::class, ['x' => 'P2Y']);

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--no-confirm' => true, '--exclude' => [Author::class]]);

        $this->assertSame(['a', 'b'], $this->remainingEntries(Author::class));
        $this->assertSame([], $this->remainingEntries(Post::class));
    }

    private function createCommand(): CleanAuditLogsCommand
    {
        $command = new CleanAuditLogsCommand();
        $command->setAuditor($this->provider->getAuditor());

        return $command;
    }

    /**
     * Inserts audit entries, in the given order, created the given ISO 8601 duration ago.
     *
     * @param array<string, string> $entries object_id => age
     */
    private function insertEntries(string $entity, array $entries): void
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $jsonCast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';
        $now = new RetentionPruner($this->provider)->now();

        foreach ($entries as $objectId => $age) {
            $connection->executeStatement(
                \sprintf('INSERT INTO %s (schema_version, type, object_id, diffs, created_at) VALUES (?, ?, ?, ?%s, ?)', $this->auditTable($entity), $jsonCast),
                [2, 'update', $objectId, '{}', $now->sub(new \DateInterval($age))->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * @return list<string> object ids of the remaining entries, in insertion order
     */
    private function remainingEntries(string $entity): array
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        /** @var list<string> */
        return array_map(strval(...), $connection->fetchFirstColumn(\sprintf('SELECT object_id FROM %s ORDER BY id', $this->auditTable($entity))));
    }

    private function auditTable(string $entity): string
    {
        $configuration = $this->provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);
        $table = new SchemaManager($this->provider)->resolveAuditTableName($entity, $configuration);
        \assert(\is_string($table));

        return $table;
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true],
            Tag::class => ['enabled' => true],

            Animal::class => ['enabled' => true],
            Cat::class => ['enabled' => true],
            Dog::class => ['enabled' => true],
        ]);
    }
}
