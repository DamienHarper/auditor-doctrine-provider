<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\Blame\BlameContextStack;
use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use DH\Auditor\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests of the blame contexts, written in the `via` key of the `blame` JSON.
 *
 * Native JSON columns (MySQL, PostgreSQL) do not keep the key order: `via` is sorted by key before comparison.
 *
 * @internal
 */
#[Small]
final class BlameContextTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testNoViaWithoutBlameContext(): void
    {
        $this->createPost('No context');

        $entry = $this->entry('No context');
        $this->assertNull($entry->via, 'via is null without blame context.');
        $this->assertArrayNotHasKey('via', $entry->blame ?? [], 'The blame JSON has no via key without blame context.');
        $this->assertContainsEquals($entry->username, ['dark.vador', 'anakin.skywalker'], 'The user provider is blamed.');
    }

    public function testViaIsWrittenInTheBlameJson(): void
    {
        $this->stack()->run(
            new BlameContext('import', 'Import batch #42', ['file' => 'posts.csv']),
            fn () => $this->createPost('Imported'),
        );

        $entry = $this->entry('Imported');
        $this->assertSame(['data' => ['file' => 'posts.csv'], 'label' => 'Import batch #42', 'type' => 'import'], $this->sorted($entry->via));
        $this->assertContainsEquals($entry->username, ['dark.vador', 'anakin.skywalker'], 'The user provider is still blamed.');
        $this->assertSame('1.2.3.4', $entry->ip, 'The security provider is still used.');
    }

    public function testTheCurrentContextIsWritten(): void
    {
        $stack = $this->stack();
        $stack->push(new BlameContext('console', 'app:import'));
        $stack->push(new BlameContext('import', 'Import batch #42'));

        try {
            $this->createPost('Nested');
        } finally {
            $stack->reset();
        }

        $this->assertSame(['type' => 'import', 'label' => 'Import batch #42'], $this->entry('Nested')->via, 'Only the top of the stack is written.');
    }

    public function testTheUserOfTheContextIsBlamed(): void
    {
        $this->stack()->run(
            new BlameContext('messenger', 'App\Message\ImportPosts', user: new User('42', 'alice')),
            fn () => $this->createPost('On behalf of Alice'),
        );

        $entry = $this->entry('On behalf of Alice');
        $this->assertSame('42', $entry->userId, 'blame_id is the identifier of the user of the context.');
        $this->assertSame('alice', $entry->username, 'The user of the context is blamed.');
        $this->assertSame(User::class, $entry->userFqdn);
        $this->assertSame(['type' => 'messenger', 'label' => 'App\Message\ImportPosts'], $entry->via);
    }

    public function testBlameIsWrittenWithAContextAndNoUser(): void
    {
        $configuration = $this->provider->getAuditor()->getConfiguration();
        $configuration->setUserProvider(static fn (): null => null);
        $configuration->setSecurityProvider(static fn (): array => [null, null]);

        $this->createPost('Anonymous');
        $this->stack()->run(new BlameContext('cron'), fn () => $this->createPost('Cron'));

        $this->assertNull($this->entry('Anonymous')->blame, 'No blame without user nor context.');

        $entry = $this->entry('Cron');
        $this->assertNull($entry->userId);
        $this->assertNull($entry->username);
        $this->assertSame(['type' => 'cron'], $entry->via, 'The blame JSON is written for a context alone.');
    }

    public function testTheContextOfEachFlushIsKeptUntilTheCommit(): void
    {
        $entityManager = $this->entityManager();

        $entityManager->wrapInTransaction(function () use ($entityManager): void {
            // popped before the COMMIT, which writes the audits
            $this->stack()->run(new BlameContext('import', 'First flush'), fn () => $this->createPost('First'));
            $this->stack()->run(new BlameContext('import', 'Second flush'), fn () => $this->createPost('Second'));
            $this->createPost('Third');
            $entityManager->flush();
        });

        $this->assertSame(['type' => 'import', 'label' => 'First flush'], $this->entry('First')->via);
        $this->assertSame(['type' => 'import', 'label' => 'Second flush'], $this->entry('Second')->via);
        $this->assertNull($this->entry('Third')->via, 'A flush without context has no via.');
    }

    public function testTheContextIsNotKeptAfterItIsPopped(): void
    {
        $this->stack()->run(new BlameContext('import'), fn () => $this->createPost('During'));
        $this->createPost('After');

        $this->assertSame(['type' => 'import'], $this->entry('During')->via);
        $this->assertNull($this->entry('After')->via, 'A popped context is not written any more.');
    }

    /**
     * @param null|array<string, mixed> $via
     *
     * @return null|array<string, mixed>
     */
    private function sorted(?array $via): ?array
    {
        if (null !== $via) {
            ksort($via);
        }

        return $via;
    }

    private function stack(): BlameContextStack
    {
        return $this->provider->getAuditor()->getConfiguration()->getBlameContextStack();
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager();
    }

    private function createPost(string $title): void
    {
        $post = new Post();
        $post->setTitle($title)->setBody('body')->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager()->persist($post);
        $this->entityManager()->flush();
    }

    private function entry(string $title): Entry
    {
        $postId = $this->entityManager()->getRepository(Post::class)->findOneBy(['title' => $title])?->getId();
        $this->assertNotNull($postId, \sprintf('Post "%s" exists.', $title));

        $entries = $this->createReader()->createQuery(Post::class, ['object_id' => (string) $postId])->execute();
        $this->assertCount(1, $entries, \sprintf('Post "%s" has one audit entry.', $title));

        return $entries[0];
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Post::class => ['enabled' => true],
        ]);
    }
}
