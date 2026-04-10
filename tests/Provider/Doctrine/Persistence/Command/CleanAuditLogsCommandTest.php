<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Animal;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Cat;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Dog;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
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
        $this->assertStringContainsString('invalid max_age value', $output);
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
        $this->assertStringNotContainsString('WHERE id NOT IN', $output);

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
        $this->assertStringContainsString('global keep: 2023-04-26 09:00:00', $output);
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
            '/DELETE FROM '.preg_quote($authorTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output
        );
        preg_match(
            '/DELETE FROM '.preg_quote($authorTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output,
            $authorMatches
        );

        // Extract the DELETE cutoff date for Post (global P12M)
        $this->assertMatchesRegularExpression(
            '/DELETE FROM '.preg_quote($postTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output
        );
        preg_match(
            '/DELETE FROM '.preg_quote($postTable, '/').' WHERE created_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'/',
            $output,
            $postMatches
        );

        // Author cutoff (P1D ago) must be more recent than Post cutoff (P12M ago)
        $authorCutoff = new \DateTimeImmutable($authorMatches[1]);
        $postCutoff = new \DateTimeImmutable($postMatches[1]);
        $this->assertGreaterThan($postCutoff, $authorCutoff, 'Per-entity max_age P1D must yield a more recent cutoff than global P12M');
    }

    public function testMaxEntriesSqlAppearsInDumpOutput(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true, 'max_entries' => 50],
            Post::class => ['enabled' => true],
        ]);

        $command = $this->createCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--no-confirm' => true,
            '--dump-sql' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('[OK] Success', $output);

        $schemaManager = new SchemaManager($this->provider);
        $configuration = $this->provider->getConfiguration();
        $authorTable = $schemaManager->resolveAuditTableName(Author::class, $configuration);
        $postTable = $schemaManager->resolveAuditTableName(Post::class, $configuration);

        // Author has max_entries — count-based DELETE must appear with correct table and limit
        $this->assertMatchesRegularExpression(
            '/DELETE FROM '.preg_quote($authorTable, '/').' WHERE id NOT IN.*LIMIT 50/',
            $output
        );

        // Post has no max_entries — no count-based DELETE for its table
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE FROM '.preg_quote($postTable, '/').' WHERE id NOT IN/',
            $output
        );
    }

    private function createCommand(): CleanAuditLogsCommand
    {
        $command = new CleanAuditLogsCommand();
        $command->setAuditor($this->provider->getAuditor());

        return $command;
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
