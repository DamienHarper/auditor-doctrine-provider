<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Blame\BlameContext;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\BlameOriginFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\FilterInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ImpersonatorFilter;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use DH\Auditor\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests of the user written in the `blame` JSON: its class (`user_fqdn`) and its impersonator.
 *
 * Native JSON columns (MySQL, PostgreSQL) do not keep the key order: the impersonator is sorted by key before comparison.
 *
 * @internal
 */
#[Small]
final class BlameUserTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testTheClassOfTheUserIsWritten(): void
    {
        $this->blame(new User('42', 'alice', 'App\Entity\Customer'));
        $this->createPost('Customer');

        $entry = $this->entry('Customer');
        $this->assertSame('App\Entity\Customer', $entry->userFqdn, 'user_fqdn is the class given by the user provider.');
        $this->assertNull($entry->impersonator);
        $this->assertArrayNotHasKey('impersonator', $entry->blame ?? [], 'The blame JSON has no impersonator key without impersonation.');
    }

    public function testNoClassWhenTheUserProviderGivesNone(): void
    {
        $this->blame(new User('42', 'alice'));
        $this->createPost('No class');

        $this->assertNull($this->entry('No class')->userFqdn, 'The class of the core User object is not written.');
    }

    public function testTheImpersonatorIsWritten(): void
    {
        $this->blame(new User('42', 'alice', 'App\Entity\Customer', new User('1', 'admin', 'App\Entity\Admin')));
        $this->createPost('Impersonated');

        $entry = $this->entry('Impersonated');
        $this->assertSame('42', $entry->userId, 'blame_id is the impersonated user.');
        $this->assertSame('alice', $entry->username, 'The impersonated user is blamed.');
        $this->assertSame('App\Entity\Customer', $entry->userFqdn);
        $this->assertSame(['fqdn' => 'App\Entity\Admin', 'id' => '1', 'username' => 'admin'], $this->sorted($entry->impersonator));
    }

    public function testTheImpersonatorAloneIsWritten(): void
    {
        $this->provider->getAuditor()->getConfiguration()->setSecurityProvider(static fn (): array => [null, null]);
        $this->blame(new User(impersonator: new User('1', 'admin')));
        $this->createPost('Impersonator alone');

        $entry = $this->entry('Impersonator alone');
        $this->assertNull($entry->userId);
        $this->assertSame(['id' => '1', 'username' => 'admin'], $this->sorted($entry->impersonator), 'The blame is written for an impersonator alone.');
    }

    public function testAnImpersonatorWithoutValueIsNotWritten(): void
    {
        $this->blame(new User('42', 'alice', 'App\Entity\Customer', new User()));
        $this->createPost('Empty impersonator');

        $entry = $this->entry('Empty impersonator');
        $this->assertArrayNotHasKey('impersonator', $entry->blame ?? [], 'An impersonator without id, username nor class is not written.');
        $this->assertSame([], $this->titles(new ImpersonatorFilter()));
    }

    public function testTheImpersonatorFilterWithTheBlameOriginFilter(): void
    {
        $this->blame(new User('42', 'alice', 'App\Entity\Customer', new User('1', 'admin', 'App\Entity\Admin')));
        $this->createPost('Web');
        $this->provider->getAuditor()->getConfiguration()->getBlameContextStack()->run(new BlameContext('console', 'app:sync'), fn () => $this->createPost('Console'));

        $this->assertSame(['Console', 'Web'], $this->titles(new ImpersonatorFilter()));
        $this->assertSame(['Web'], $this->titles(new ImpersonatorFilter('1'), new BlameOriginFilter(BlameOrigin::Http)), 'Both blame filters apply.');
        $this->assertSame(['Console'], $this->titles(new BlameOriginFilter(BlameOrigin::Console), new ImpersonatorFilter()), 'Both blame filters apply, in any order.');
    }

    public function testTheImpersonatorFilter(): void
    {
        $this->blame(new User('42', 'alice', 'App\Entity\Customer', new User('1', 'admin', 'App\Entity\Admin')));
        $this->createPost('By admin');
        $this->blame(new User('43', 'bob', 'App\Entity\Customer', new User('2', 'support', 'App\Entity\Admin')));
        $this->createPost('By support');
        $this->blame(new User('42', 'alice', 'App\Entity\Customer'));
        $this->createPost('By alice');

        $this->assertSame(['By admin', 'By support'], $this->titles(new ImpersonatorFilter()), 'Entries made by impersonating someone.');
        $this->assertSame(['By support'], $this->titles(new ImpersonatorFilter('2')), 'Entries made by impersonating someone, by the id of the impersonator.');
        $this->assertSame([], $this->titles(new ImpersonatorFilter('42')), 'The id of the impersonated user does not match.');
    }

    private function blame(User $user): void
    {
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): User => $user);
    }

    /**
     * @return list<string>
     */
    private function titles(FilterInterface ...$filters): array
    {
        $query = $this->createReader()->createQuery(Post::class, ['page_size' => null]);
        foreach ($filters as $filter) {
            $query->addFilter($filter);
        }

        $titles = array_map(static fn (Entry $entry): string => (string) ($entry->getDiffs()['title']['new'] ?? ''), $query->execute());
        sort($titles);

        return $titles;
    }

    /**
     * @param null|array<string, mixed> $values
     *
     * @return null|array<string, mixed>
     */
    private function sorted(?array $values): ?array
    {
        if (null !== $values) {
            ksort($values);
        }

        return $values;
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
