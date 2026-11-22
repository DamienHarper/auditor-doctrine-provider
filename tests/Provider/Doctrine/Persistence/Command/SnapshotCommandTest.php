<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Command;

use DH\Auditor\Provider\Doctrine\Persistence\Command\SnapshotCommand;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Animal;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Cat;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\Joined\Dog;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[Small]
final class SnapshotCommandTest extends TestCase
{
    use SchemaSetupTrait;

    public function testSnapshotGivenEntities(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['entities' => [Author::class]]);

        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Snapshot done.', $commandTester->getDisplay());
        $this->assertSame(2, $this->countAuditRows(Author::class));
        $this->assertSame(0, $this->countAuditRows(Post::class));
    }

    public function testSnapshotAllSnapshotsAnInheritanceHierarchyOnce(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['--all' => true, '--batch-size' => '1']);

        $commandTester->assertCommandIsSuccessful();

        $display = $commandTester->getDisplay();
        $this->assertStringContainsString(Animal::class, $display);
        $this->assertStringNotContainsString(' '.Cat::class, $display, 'Subclasses are read through their parent.');
        $this->assertSame(2, $this->countAuditRows(Author::class));
        $this->assertSame(1, $this->countAuditRows(Post::class));
        $this->assertSame(1, $this->countAuditRows(Cat::class), 'A cat is written to the audit table of its own class.');
        $this->assertSame(1, $this->countAuditRows(Dog::class));
        $this->assertSame(0, $this->countAuditRows(Animal::class));

        $commandTester->execute(['--all' => true]);
        $this->assertSame(2, $this->countAuditRows(Author::class), 'A second run writes nothing.');
    }

    public function testDryRun(): void
    {
        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute(['entities' => [Author::class], '--dry-run' => true]);

        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('To snapshot', $commandTester->getDisplay());
        $this->assertStringContainsString('Dry run: nothing was written.', $commandTester->getDisplay());
        $this->assertSame(0, $this->countAuditRows(Author::class));
    }

    public function testEntitiesOrAllIsRequired(): void
    {
        $commandTester = new CommandTester($this->createCommand());

        $this->assertSame(Command::INVALID, $commandTester->execute([]));
        $this->assertSame(Command::INVALID, $commandTester->execute(['entities' => [Author::class], '--all' => true]));
        $this->assertStringContainsString('Give the entities to snapshot, or the "--all" option.', $commandTester->getDisplay());
    }

    public function testInvalidBatchSize(): void
    {
        $commandTester = new CommandTester($this->createCommand());

        $this->assertSame(Command::INVALID, $commandTester->execute(['--all' => true, '--batch-size' => '0']));
        $this->assertStringContainsString('must be a positive integer', $commandTester->getDisplay());
    }

    public function testUnknownEntity(): void
    {
        $commandTester = new CommandTester($this->createCommand());

        $this->assertSame(Command::FAILURE, $commandTester->execute(['entities' => ['App\Entity\Unknown']]));
        $this->assertStringContainsString('is not audited', $commandTester->getDisplay());
    }

    private function createCommand(): SnapshotCommand
    {
        $command = new SnapshotCommand();
        $command->setAuditor($this->provider->getAuditor());

        return $command;
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Animal::class => ['enabled' => true],
            Cat::class => ['enabled' => true],
            Dog::class => ['enabled' => true],
        ]);
    }

    private function setupEntities(): void
    {
        $configuration = $this->provider->getAuditor()->getConfiguration();
        $configuration->disable();

        $entityManager = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $author = new Author();
        $author->setFullname('John')->setEmail('john.doe@gmail.com');
        $entityManager->persist($author);

        $other = new Author();
        $other->setFullname('Chuck Norris')->setEmail('chuck.norris@gmail.com');
        $entityManager->persist($other);

        $post = new Post();
        $post->setTitle('First post')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2020-01-17 22:17:34'))->setAuthor($author);
        $entityManager->persist($post);

        $entityManager->persist(new Cat()->setLabel('Tom'));
        $entityManager->persist(new Dog()->setLabel('Rex'));
        $entityManager->flush();
        $entityManager->clear();

        $configuration->enable();
    }

    private function countAuditRows(string $entity): int
    {
        $table = $this->provider->getConfiguration()->getEntities()[$entity]['computed_audit_table_name'];
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        return (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
