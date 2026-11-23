<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\History\ChangeKind;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class RootQueryTest extends TestCase
{
    use SchemaSetupTrait;

    private int $batchSize = 0;

    private Author $alice;

    private Author $bob;

    private Post $post;

    private Comment $first;

    private Comment $second;

    public function testHistoryOfARoot(): void
    {
        $query = $this->reader()->createRootQuery(Author::class, $this->id($this->alice));
        $entries = iterator_to_array($query->iterate(), false);

        $this->assertSame([
            'Author#1 update -',
            'Post#1 update author',         // moved to Bob: indexed under its old author too
            'Author#1 dissociate -',
            'Comment#1 update post.author',
            'Post#1 update author',
            'Post#1 associate author',
            'Post#1 associate author',
            'Post#1 insert author',
            'Comment#1 insert post.author',
            'Comment#2 insert post.author',
            'Author#1 associate -',
            'Author#1 insert -',
        ], array_map($this->describe(...), $entries), 'The edit of the second comment, made after the move, is not in the history of Alice.');
        $this->assertSame(12, $query->count());
        $this->assertCount(5, array_unique(array_map(static fn (Entry $entry): ?string => $entry->transactionId, $entries)));
    }

    public function testHistoryOfTheNewRoot(): void
    {
        $entries = iterator_to_array($this->reader()->createRootQuery(Author::class, $this->id($this->bob), ['self' => false])->iterate(), false);

        $this->assertSame(['Comment#2 update post.author', 'Post#1 update author'], array_map($this->describe(...), $entries));
    }

    public function testPathsAndIncludeOptions(): void
    {
        $reader = $this->reader();

        $comments = iterator_to_array($reader->createRootQuery(Author::class, $this->id($this->alice), ['paths' => ['post.author']])->iterate(), false);
        $this->assertSame(['Comment#1 update post.author', 'Comment#1 insert post.author', 'Comment#2 insert post.author'], array_map($this->describe(...), $comments), 'Filtering on paths leaves the root itself out.');

        $posts = iterator_to_array($reader->createRootQuery(Author::class, $this->id($this->alice), ['include' => [Post::class], 'self' => false])->iterate(), false);
        $this->assertCount(5, $posts);
        $this->assertSame([Post::class], array_values(array_unique(array_map(static fn (Entry $entry): ?string => $entry->entity, $posts))));
    }

    public function testFiltersApplyToEveryTable(): void
    {
        $reader = $this->reader();
        $id = $this->id($this->alice);

        $this->assertSame(4, $reader->createRootQuery(Author::class, $id, ['type' => 'insert'])->count());
        $this->assertSame(12, $reader->createRootQuery(Author::class, $id, ['blame_origin' => BlameOrigin::Http])->count());
        $this->assertSame(0, $reader->createRootQuery(Author::class, $id, ['blame_origin' => ['console', 'messenger']])->count());
    }

    public function testPagesKeepTransactionsWhole(): void
    {
        $query = $this->reader()->createRootQuery(Author::class, $this->id($this->alice));
        $all = array_map($this->describe(...), iterator_to_array($query->iterate(), false));

        $page = $query->fetch(2);
        $this->assertSame(['Author#1 update -', 'Post#1 update author', 'Author#1 dissociate -'], array_map($this->describe(...), $page->entries), 'The page is extended to the end of the transaction of its last entry.');
        $this->assertTrue($page->hasMore);
        $this->assertCount(2, $page->group());

        $exact = $query->fetch(2, groupKey: false);
        $this->assertCount(2, $exact->entries);

        // reading page after page gives the whole history, once
        foreach ([1, 2, 5, 100] as $limit) {
            foreach ([null, false] as $groupKey) {
                $read = [];
                $after = null;
                do {
                    $page = $query->fetch($limit, $after, groupKey: $groupKey);
                    array_push($read, ...array_map($this->describe(...), $page->entries));
                    $after = $page->lastCursor;
                } while ($page->hasMore);

                $this->assertSame($all, $read, \sprintf('Pages of %d entries.', $limit));
            }
        }
    }

    public function testNewerEntriesSinceACursor(): void
    {
        $query = $this->reader()->createRootQuery(Author::class, $this->id($this->alice));
        $cursors = array_keys(iterator_to_array($query->iterate()));

        // since the 4th entry (the edit of the first comment)
        $newer = $query->fetch(50, since: $cursors[3]);
        $this->assertSame(['Author#1 update -', 'Post#1 update author', 'Author#1 dissociate -'], array_map($this->describe(...), $newer->entries));
        $this->assertFalse($newer->hasMore);
        $this->assertSame($cursors[0], $newer->firstCursor);

        $this->assertTrue($query->fetch(50, since: $cursors[0])->isEmpty());
    }

    public function testInvalidCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader()->createRootQuery(Author::class, $this->id($this->alice))->fetch(10, 'not-a-cursor');
    }

    public function testOnlyTheRolesOfTheRootAreChecked(): void
    {
        $this->provider->getAuditor()->getConfiguration()->setRoleChecker(static fn (string $entity): bool => Author::class === $entity);

        $this->assertSame(12, $this->reader()->createRootQuery(Author::class, $this->id($this->alice))->count());

        $this->expectException(AccessDeniedException::class);
        $this->reader()->createRootQuery(Post::class, $this->id($this->post));
    }

    public function testBatchedRootsAreIndexed(): void
    {
        $this->tearDownEntitySchemas();
        $this->tearDownAuditSchemas();
        $this->batchSize = 3;
        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
        $this->setupEntities();

        $this->assertSame(12, $this->reader()->createRootQuery(Author::class, $this->id($this->alice))->count());
        $this->assertSame(2, $this->reader()->createRootQuery(Author::class, $this->id($this->bob), ['self' => false])->count());
    }

    public function testLastChanges(): void
    {
        $changes = $this->reader()->getLastChanges(Post::class, $this->id($this->post), ['title', 'body']);

        $this->assertSame(ChangeKind::Changed, $changes['title']?->kind);
        $this->assertSame('Hello', $changes['title']->old);
        $this->assertSame('Hello world', $changes['title']->new);
        $this->assertSame(ChangeKind::Created, $changes['body']?->kind, 'A field never changed comes with the creation of the entity.');
        $this->assertSame('Body', $changes['body']->getValue('body'));
        $this->assertSame('Hello world', $changes['title']->getValue('title'));
        $this->assertSame([], $this->reader()->getLastChanges(Post::class, $this->id($this->post), []));
        $this->assertSame(['title' => null], $this->reader()->getLastChanges(Post::class, 999, ['title']));
    }

    public function testFieldHistory(): void
    {
        $entries = $this->reader()->getFieldHistory(Comment::class, null, 'body')->execute();

        $this->assertSame(['Comment#2 update', 'Comment#1 update', 'Comment#1 insert', 'Comment#2 insert'], array_map(static fn (Entry $entry): string => 'Comment#'.$entry->objectId.' '.$entry->type, $entries));
        $this->assertCount(2, $this->reader()->getFieldHistory(Comment::class, $this->id($this->first), 'body')->execute());
    }

    public function testReadScopeAppliesToEveryQuery(): void
    {
        $this->provider->getConfiguration()->setReadScope(static function (Query $query, string $entity): void {
            $query->addFilter(new SimpleFilter(Query::TYPE, 'insert'));
        });
        $reader = $this->reader();
        $id = $this->id($this->alice);

        $history = $reader->createRootQuery(Author::class, $id);
        $this->assertSame(4, $history->count());
        $this->assertCount(4, iterator_to_array($history->iterate(), false));
        $this->assertSame(['insert'], array_values(array_unique(array_map(static fn (Entry $entry): string => $entry->type, $history->fetch(100, groupKey: false)->entries))));
        $this->assertCount(2, $reader->createQuery(Comment::class)->execute());

        $changes = $reader->getLastChanges(Post::class, $this->id($this->post), ['title']);
        $this->assertSame(ChangeKind::Created, $changes['title']?->kind, 'The update of the title is out of the scope.');
        $this->assertSame('Hello', $changes['title']->getValue('title'));
    }

    public function testSeveralFlushesInOneTransaction(): void
    {
        $reader = $this->reader();
        $alice = $reader->createRootQuery(Author::class, $this->id($this->alice), ['self' => false])->count();
        $bob = $reader->createRootQuery(Author::class, $this->id($this->bob), ['self' => false])->count();

        $this->entityManager()->wrapInTransaction(function (EntityManagerInterface $em): void {
            $this->first->setBody('Edited under Bob');
            $em->flush();

            $this->post->setAuthor($this->alice);
            $em->flush();
        });

        $this->assertSame($bob + 2, $reader->createRootQuery(Author::class, $this->id($this->bob), ['self' => false])->count(), 'The comment edit and the move are in the history of Bob.');
        $this->assertSame($alice + 1, $reader->createRootQuery(Author::class, $this->id($this->alice), ['self' => false])->count(), 'The move is in the history of Alice.');
    }

    public function testBlameOrigins(): void
    {
        $em = $this->entityManager();
        $stack = $this->provider->getAuditor()->getConfiguration()->getBlameContextStack();
        $stack->run(new BlameContext('console', 'app:rename'), function () use ($em): void {
            $this->alice->setFullname('Alice (console)');
            $em->flush();
        });
        $stack->run(new BlameContext('messenger', 'App\Message\Rename'), function () use ($em): void {
            $this->alice->setFullname('Alice (messenger)');
            $em->flush();
        });
        $this->provider->getAuditor()->getConfiguration()->setSecurityProvider(static fn (): array => [null, null]);
        $this->alice->setFullname('Alice (system)');
        $em->flush();

        $reader = $this->reader();
        $id = $this->id($this->alice);
        foreach ([BlameOrigin::Console, BlameOrigin::Messenger, BlameOrigin::System] as $origin) {
            $this->assertSame(1, $reader->createRootQuery(Author::class, $id, ['blame_origin' => $origin])->count(), $origin->value);
        }

        $this->assertSame(12, $reader->createRootQuery(Author::class, $id, ['blame_origin' => BlameOrigin::Http])->count());
    }

    public function testInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader()->createRootQuery(Author::class, $this->id($this->alice), ['paths' => ['unknown']]);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true, 'roots' => ['author']],
            Comment::class => ['enabled' => true, 'roots' => ['post', 'post.author']],
            Tag::class => ['enabled' => true],
        ]);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['batch_size' => $this->batchSize]));
    }

    private function setupEntities(): void
    {
        $em = $this->entityManager();

        // T1: Alice writes a post, commented twice
        $this->alice = new Author()->setFullname('Alice')->setEmail('alice@example.com');
        $this->bob = new Author()->setFullname('Bob')->setEmail('bob@example.com');
        $this->post = new Post()->setTitle('Hello')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setAuthor($this->alice);
        $this->first = new Comment()->setBody('First')->setAuthor('Carol')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setPost($this->post);
        $this->second = new Comment()->setBody('Second')->setAuthor('Dave')->setCreatedAt(new \DateTimeImmutable('2026-01-01'))->setPost($this->post);
        foreach ([$this->alice, $this->bob, $this->post, $this->first, $this->second] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        // T2: the post is renamed
        $this->post->setTitle('Hello world');
        $em->flush();

        // T3: a comment is edited
        $this->first->setBody('First!');
        $em->flush();

        // T4: the post moves to Bob
        $this->post->setAuthor($this->bob);
        $em->flush();

        // T5: Alice is renamed, the second comment is edited (now under Bob)
        $this->alice->setFullname('Alice Martin');
        $this->second->setBody('Second!');
        $em->flush();
    }

    private function reader(): Reader
    {
        return new Reader($this->provider);
    }

    private function id(object $entity): int
    {
        $this->assertTrue(method_exists($entity, 'getId'));
        $id = $entity->getId();
        \assert(\is_int($id));

        return $id;
    }

    private function describe(Entry $entry): string
    {
        $class = (string) $entry->entity;

        return \sprintf('%s#%s %s %s', mb_substr($class, (int) mb_strrpos($class, '\\') + 1), $entry->objectId, $entry->type, $entry->rootPath ?? '-');
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
    }
}
