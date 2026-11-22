<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\Snapshot;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use DH\Auditor\User\User;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class SnapshotterTest extends TestCase
{
    use SchemaSetupTrait;

    public function testSnapshotWritesAnInsertEntryFlaggedAsSnapshot(): void
    {
        $result = new Snapshotter($this->provider)->snapshot(Author::class);

        $this->assertSame(3, $result->read);
        $this->assertSame(3, $result->snapshotted);
        $this->assertSame(0, $result->skipped());

        $rows = $this->auditRows(Author::class);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('insert', $row['type']);
            $this->assertSame(2, (int) $row['schema_version']);
            $diffs = $this->decode($row['diffs']);
            $this->assertTrue($diffs['snapshot'], 'The entry carries the snapshot marker.');
            $this->assertSame(Author::class, $diffs['source']['class']);
            $this->assertSame($row['object_id'], (string) $diffs['source']['id']);
        }

        $diffs = $this->decode($rows[0]['diffs']);
        $this->assertEqualsCanonicalizing(['email', 'fullname'], array_keys($diffs['changes']), 'Every audited field but the generated id is recorded.');
        $this->assertSame(['new' => 'John', 'old' => null], $diffs['changes']['fullname']);
    }

    public function testSnapshotWritesTheCurrentBlameContext(): void
    {
        $this->provider->getAuditor()->getConfiguration()->getBlameContextStack()->run(
            new BlameContext('console', 'audit:snapshot', user: new User('7', 'ops')),
            fn () => new Snapshotter($this->provider)->snapshot(Author::class),
        );

        $row = $this->auditRows(Author::class)[0];
        $this->assertSame('7', $row['blame_id']);
        $blame = $this->decode($row['blame']);
        $this->assertSame('ops', $blame['username']);
        $this->assertSame(['type' => 'console', 'label' => 'audit:snapshot'], $blame['via']);
    }

    public function testSnapshotSummarizesOwningSideToOneAssociations(): void
    {
        new Snapshotter($this->provider)->snapshot(Post::class);

        $diffs = $this->decode($this->auditRows(Post::class)[0]['diffs']);
        $this->assertSame('John', $diffs['changes']['author']['new']['label']);
        $this->assertSame(Author::class, $diffs['changes']['author']['new']['class']);
        $this->assertNull($diffs['changes']['author']['old']);
        $this->assertArrayNotHasKey('coauthor', $diffs['changes'], 'A null association is left out, as in an insert.');
        $this->assertArrayNotHasKey('tags', $diffs['changes'], 'To-many associations are not part of a snapshot.');
    }

    public function testSnapshotSkipsEntitiesThatAlreadyHaveAnHistory(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $author = $entityManager->getRepository(Author::class)->findOneBy(['fullname' => 'John']);
        $this->assertInstanceOf(Author::class, $author);
        $author->setEmail('john@example.com');
        $entityManager->flush();
        $entityManager->clear();

        $snapshotter = new Snapshotter($this->provider);
        $first = $snapshotter->snapshot(Author::class);
        $this->assertSame(2, $first->snapshotted, 'The author with an update entry is skipped.');
        $this->assertSame(1, $first->skipped());

        $second = $snapshotter->snapshot(Author::class);
        $this->assertSame(0, $second->snapshotted, 'A second run writes nothing.');
        $this->assertSame(3, $second->skipped());
        $this->assertCount(3, $this->auditRows(Author::class));
    }

    public function testForceSnapshotsEntitiesWithAnHistory(): void
    {
        $snapshotter = new Snapshotter($this->provider);
        $snapshotter->snapshot(Author::class);

        $result = $snapshotter->snapshot(Author::class, force: true);

        $this->assertSame(3, $result->snapshotted);
        $this->assertCount(6, $this->auditRows(Author::class));
    }

    public function testDryRunWritesNothing(): void
    {
        $result = new Snapshotter($this->provider)->snapshot(Author::class, dryRun: true);

        $this->assertSame(3, $result->snapshotted, 'A dry run counts the entities to snapshot.');
        $this->assertCount(0, $this->auditRows(Author::class));
    }

    public function testEachBatchIsOneTransaction(): void
    {
        $batches = [];
        $result = new Snapshotter($this->provider)->snapshot(
            Author::class,
            batchSize: 2,
            onBatch: static function (int $read, int $snapshotted) use (&$batches): void {
                $batches[] = [$read, $snapshotted];
            },
        );

        $this->assertSame([[2, 2], [1, 1]], $batches);
        $this->assertSame(3, $result->snapshotted);

        $transactionIds = array_count_values(array_column($this->auditRows(Author::class), 'transaction_id'));
        $this->assertSame([2, 1], array_values($transactionIds), 'Entities of a batch share a transaction id.');
    }

    public function testCount(): void
    {
        $this->assertSame(3, new Snapshotter($this->provider)->count(Author::class));
    }

    public function testSnapshotRefusesAnEntityThatIsNotAudited(): void
    {
        $this->provider->getConfiguration()->setEntities([Post::class => ['enabled' => false]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not audited');

        new Snapshotter($this->provider)->snapshot(Post::class);
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

    /**
     * Existing data, written while auditing is disabled.
     */
    private function setupEntities(): void
    {
        $configuration = $this->provider->getAuditor()->getConfiguration();
        $configuration->disable();

        $entityManager = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $authors = [];
        foreach (['John' => 'john.doe@gmail.com', 'Chuck Norris' => 'chuck.norris@gmail.com', 'Luke Skywalker' => 'luke.skywalker@gmail.com'] as $name => $email) {
            $author = new Author();
            $author->setFullname($name)->setEmail($email);
            $entityManager->persist($author);
            $authors[] = $author;
        }

        $post = new Post();
        $post
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTimeImmutable('2020-01-17 22:17:34'))
            ->setAuthor($authors[0])
        ;
        $entityManager->persist($post);
        $entityManager->flush();
        $entityManager->clear();

        $configuration->enable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(string $entity): array
    {
        $table = $this->provider->getConfiguration()->getEntities()[$entity]['computed_audit_table_name'];
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        return $connection->fetchAllAssociative(\sprintf('SELECT * FROM %s ORDER BY id ASC', $table));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        $this->assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
