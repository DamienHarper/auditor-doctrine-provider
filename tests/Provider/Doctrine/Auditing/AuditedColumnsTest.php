<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\IgnoredColumnsResolverInterface;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the "audited_columns" allowlist and the "ignored_columns_resolver".
 *
 * @internal
 */
#[Small]
final class AuditedColumnsTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testOnlyAllowlistedColumnsAreAudited(): void
    {
        $this->configurePost(['audited_columns' => ['title']]);

        $post = $this->createPost('First', 'Body');

        $post->setBody('Another body');
        $this->flush();

        $post->setTitle('Second');
        $this->flush();

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertCount(2, $audits, 'The update of a column missing from the allowlist is not audited.');

        $this->assertSame('update', $audits[0]->type);
        $this->assertSame(['title' => ['new' => 'Second', 'old' => 'First']], $audits[0]->getDiffs());

        $this->assertSame('insert', $audits[1]->type);
        $this->assertSame(['title' => ['new' => 'First', 'old' => null]], $audits[1]->getDiffs());
    }

    public function testEmptyAllowlistOnlyAuditsInsertsAndRemovals(): void
    {
        $this->configurePost(['audited_columns' => []]);

        $post = $this->createPost('First', 'Body');

        $post->setTitle('Second');
        $this->flush();

        $this->getEntityManager()->remove($post);
        $this->flush();

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertSame(['remove', 'insert'], array_map(static fn (Entry $entry): string => $entry->type, $audits));
        $this->assertSame([], $audits[1]->getDiffs());
    }

    public function testIgnoredColumnsWinOverTheAllowlist(): void
    {
        $this->configurePost([
            'audited_columns' => ['title', 'body', 'created_at'],
            'ignored_columns' => ['body'],
        ]);

        $this->createPost('First', 'Body');

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertCount(1, $audits);
        $this->assertSame(['created_at', 'title'], $this->sortedKeys($audits[0]->getDiffs()));
    }

    public function testGloballyIgnoredColumnsWinOverTheAllowlist(): void
    {
        $this->configurePost(['audited_columns' => ['title', 'created_at']]);

        $this->createPost('First', 'Body');

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertCount(1, $audits);
        $this->assertSame(['title'], $this->sortedKeys($audits[0]->getDiffs()));
    }

    public function testResolverIgnoresColumnsPerObject(): void
    {
        $this->configurePost([]);
        $this->provider->getConfiguration()->setIgnoredColumnsResolver(new class implements IgnoredColumnsResolverInterface {
            public function __invoke(object $entity): array
            {
                return $entity instanceof Post && 'Secret' === $entity->getTitle() ? ['body'] : [];
            }
        });

        $public = $this->createPost('Public', 'Visible body');
        $secret = $this->createPost('Secret', 'Hidden body');

        $public->setBody('Visible change');
        $secret->setBody('Hidden change');
        $this->flush();

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $byObject = [];
        foreach ($audits as $audit) {
            $byObject[$audit->objectId][] = $audit;
        }

        $this->assertCount(2, $byObject[(string) $public->getId()], 'The insert and the update of the public post are audited.');
        $this->assertSame(['new' => 'Visible change', 'old' => 'Visible body'], $byObject[(string) $public->getId()][0]->getDiffs()['body']);

        $this->assertCount(1, $byObject[(string) $secret->getId()], 'The update of an ignored column of the secret post is not audited.');
        $this->assertArrayNotHasKey('body', $byObject[(string) $secret->getId()][0]->getDiffs());
        $this->assertArrayHasKey('title', $byObject[(string) $secret->getId()][0]->getDiffs());
    }

    public function testResolverCannotAuditAnIgnoredColumn(): void
    {
        $this->configurePost(['audited_columns' => ['title']]);
        $this->provider->getConfiguration()->setIgnoredColumnsResolver(static fn (object $entity): array => []);

        $this->createPost('First', 'Body');

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertSame(['title'], $this->sortedKeys($audits[0]->getDiffs()));
    }

    public function testResolverMustReturnAnArrayOfStrings(): void
    {
        $this->configurePost([]);
        $this->provider->getConfiguration()->setIgnoredColumnsResolver(static fn (object $entity): array => [42]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The ignored columns resolver must return an array of property names, an array holding int returned for "'.Post::class.'".');

        $this->createPost('First', 'Body');
    }

    public function testFullSnapshotOnRemoveHonorsTheAllowlistAndTheResolver(): void
    {
        $this->configurePost(['audited_columns' => ['title', 'body', 'created_at']]);
        $this->provider->getConfiguration()->setIgnoredColumnsResolver(static fn (object $entity): array => ['created_at']);

        $post = $this->createPost('First', 'Body');
        $this->getEntityManager()->remove($post);
        $this->flush();

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertSame('remove', $audits[0]->type);
        $this->assertSame(['body', 'title'], $this->sortedKeys($audits[0]->getDiffs()));
    }

    public function testIsAuditedFieldHonorsTheAllowlistAndTheResolver(): void
    {
        $this->configurePost([
            'audited_columns' => ['title', 'body'],
            'ignored_columns' => ['body'],
        ]);
        $this->provider->getConfiguration()->setIgnoredColumnsResolver(static fn (object $entity): array => ['title']);

        $this->assertTrue($this->provider->isAuditedField(Post::class, 'title'), 'The resolver is not applied to a class name.');
        $this->assertFalse($this->provider->isAuditedField(Post::class, 'body'), 'An ignored column is not audited, even when allowlisted.');
        $this->assertFalse($this->provider->isAuditedField(Post::class, 'created_at'), 'A column missing from the allowlist is not audited.');
        $this->assertFalse($this->provider->isAuditedField(new Post(), 'title'), 'The resolver is applied to an object.');
    }

    private function createAndInitDoctrineProvider(): void
    {
        // provider options cannot be changed once the schema is set up: pick them from the test name
        $options = match ($this->name()) {
            'testGloballyIgnoredColumnsWinOverTheAllowlist' => ['ignored_columns' => ['created_at']],
            'testFullSnapshotOnRemoveHonorsTheAllowlistAndTheResolver' => ['full_snapshot_on_remove' => true],
            default => [],
        };

        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration($options));
    }

    private function configureEntities(): void
    {
        $this->configurePost([]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function configurePost(array $options): void
    {
        $this->provider->getConfiguration()->setEntities([Post::class => ['enabled' => true, ...$options]]);
    }

    private function createPost(string $title, string $body): Post
    {
        $post = new Post();
        $post->setTitle($title);
        $post->setBody($body);
        $post->setCreatedAt(new \DateTimeImmutable('2026-11-22 12:00:00'));

        $this->getEntityManager()->persist($post);
        $this->flush();

        return $post;
    }

    private function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager();
    }

    /**
     * @param array<string, mixed> $diffs
     *
     * @return list<string>
     */
    private function sortedKeys(array $diffs): array
    {
        $keys = array_map(strval(...), array_keys($diffs));
        sort($keys);

        return $keys;
    }
}
