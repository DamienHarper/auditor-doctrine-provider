<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\ExportAuditLogsCommand;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[Small]
final class ExportAuditLogsCommandTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testExportNdjsonToStdout(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['entity' => Author::class]);

        $output = $commandTester->getDisplay();
        $lines = array_filter(explode("\n", mb_trim($output)));
        $this->assertNotEmpty($lines, 'NDJSON output must not be empty.');

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Each NDJSON line must be valid JSON.');
            $this->assertArrayHasKey('id', $decoded);
            $this->assertArrayHasKey('type', $decoded);
            $this->assertArrayHasKey('object_id', $decoded);
            $this->assertArrayHasKey('created_at', $decoded);
        }
    }

    public function testExportJsonToFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'audit_export_').'.json';

        try {
            $commandTester = new CommandTester($this->createCommand());
            $commandTester->execute([
                'entity' => Author::class,
                '--format' => 'json',
                '--output' => $tmpFile,
            ]);

            $this->assertFileExists($tmpFile);
            $content = file_get_contents($tmpFile);
            $decoded = json_decode($content, true);
            $this->assertIsArray($decoded, 'JSON output must decode to an array.');
            $this->assertNotEmpty($decoded);
            $this->assertArrayHasKey('type', $decoded[0]);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testExportCsvToFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'audit_export_').'.csv';

        try {
            $commandTester = new CommandTester($this->createCommand());
            $commandTester->execute([
                'entity' => Author::class,
                '--format' => 'csv',
                '--output' => $tmpFile,
            ]);

            $this->assertFileExists($tmpFile);
            $handle = fopen($tmpFile, 'r');
            $this->assertIsResource($handle);

            $headers = fgetcsv($handle, escape: '\\');
            $this->assertIsArray($headers);
            $this->assertContains('id', $headers);
            $this->assertContains('type', $headers);
            $this->assertContains('object_id', $headers);

            $row = fgetcsv($handle, escape: '\\');
            $this->assertIsArray($row, 'CSV must contain at least one data row.');
            fclose($handle);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testExportWithEntityFilter(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['entity' => Tag::class]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            // Tags only have insert entries (5 tags created)
            $this->assertIsArray($decoded);
        }

        $this->assertNotEmpty($lines, 'Should export at least one Tag audit entry.');
    }

    public function testExportWithIdFilter(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([
            'entity' => Author::class,
            '--id' => '1',
        ]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertSame('1', $decoded['object_id'], '--id filter must restrict to matching object_id.');
        }
    }

    public function testExportWithDateRange(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([
            'entity' => Author::class,
            '--from' => '2000-01-01',
            '--to' => '2099-12-31',
        ]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        $this->assertNotEmpty($lines, 'Wide date range should return all entries.');
    }

    public function testExportWithDateRangeExcludesAll(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([
            'entity' => Author::class,
            '--from' => '1970-01-01',
            '--to' => '1970-01-02',
        ]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        $this->assertEmpty($lines, 'Past date range should return no entries.');
    }

    public function testExportAnonymize(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([
            'entity' => Author::class,
            '--anonymize' => true,
        ]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertNull($decoded['blame_id'], '--anonymize must null out blame_id.');
            $this->assertNull($decoded['blame_user'], '--anonymize must null out blame_user.');
            $this->assertNull($decoded['ip'], '--anonymize must null out ip.');
        }
    }

    public function testExportFailsWithInvalidFormat(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $exitCode = $commandTester->execute([
            'entity' => Author::class,
            '--format' => 'xml',
        ]);

        $this->assertSame(1, $exitCode, 'Invalid format must return failure exit code.');
        $this->assertStringContainsString("Invalid format 'xml'", $commandTester->getDisplay());
    }

    public function testExportFailsWithInvalidFromDate(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $exitCode = $commandTester->execute([
            'entity' => Author::class,
            '--from' => 'not-a-date',
        ]);

        $this->assertSame(1, $exitCode, 'Invalid date must return failure exit code.');
        $this->assertStringContainsString('Invalid date for --from', $commandTester->getDisplay());
    }

    public function testExportFailsWithInvalidToDate(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $exitCode = $commandTester->execute([
            'entity' => Author::class,
            '--to' => 'not-a-date',
        ]);

        $this->assertSame(1, $exitCode, 'Invalid date must return failure exit code.');
        $this->assertStringContainsString('Invalid date for --to', $commandTester->getDisplay());
    }

    public function testExportAllEntitiesWhenNoEntityArgument(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([]);

        $lines = array_filter(explode("\n", mb_trim($commandTester->getDisplay())));
        $this->assertNotEmpty($lines, 'Export without entity argument must include all auditable entity entries.');

        $types = array_unique(array_column(
            array_map(static fn (string $line): mixed => json_decode($line, true), $lines),
            'type'
        ));
        $this->assertNotEmpty($types);
    }

    private function createCommand(): ExportAuditLogsCommand
    {
        $command = new ExportAuditLogsCommand();
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
        ]);
    }
}
