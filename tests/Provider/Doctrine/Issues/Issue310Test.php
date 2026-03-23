<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #310: inverse-side (OneToMany/mappedBy) associations are not
 * fully audited when multiple entities are flushed together via the owning-side ManyToOne.
 *
 * Doctrine's UoW does not reliably schedule inverse-side collections for update when the FK
 * change happens through the owning-side ManyToOne field. As a result, some or all ASSOCIATE
 * entries were silently dropped on the inverse side's audit table.
 *
 * The fix scans the owning side's entity changeset instead, which Doctrine always tracks
 * reliably, and derives the inverse-side associate/dissociate entries from it.
 *
 * @see https://github.com/DamienHarper/auditor/issues/310
 *
 * @internal
 */
#[Small]
final class Issue310Test extends TestCase
{
    use DefaultSchemaSetupTrait;

    /**
     * When N entities are created with a ManyToOne field set in a single flush, the inverse
     * side's audit table must contain exactly N ASSOCIATE entries — one per entity.
     */
    public function testAllAssociateEntriesAreRecordedWhenMultipleEntitiesAreSetViaOwningSide(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $reader = new Reader($this->provider);

        $author = new Author();
        $author->setFullname('John Doe')->setEmail('john@example.com');
        $em->persist($author);
        $em->flush();

        // Create 3 posts assigned to the same author via the owning-side setter only.
        // The inverse collection (author.posts) is NOT explicitly updated.
        for ($i = 1; $i <= 3; ++$i) {
            $post = new Post();
            $post->setTitle("Post {$i}")->setBody('Body')->setCreatedAt(new \DateTimeImmutable());
            $post->setAuthor($author); // owning side only
            $em->persist($post);
        }
        $em->flush(); // single flush for all 3 posts

        $authorEntries = $reader->createQuery(Author::class, ['type' => TransactionType::ASSOCIATE])->execute();

        $this->assertCount(
            3,
            $authorEntries,
            'author_audit must contain one ASSOCIATE entry per post even when set via the owning side only.'
        );
    }

    /**
     * When a ManyToOne field is re-assigned (old → new target), both a DISSOCIATE entry on
     * the old target and an ASSOCIATE entry on the new target must appear in the audit trail.
     */
    public function testDissociateAndAssociateAreRecordedOnReassignment(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $reader = new Reader($this->provider);

        $authorA = new Author();
        $authorA->setFullname('Author A')->setEmail('a@example.com');
        $em->persist($authorA);

        $authorB = new Author();
        $authorB->setFullname('Author B')->setEmail('b@example.com');
        $em->persist($authorB);

        $post = new Post();
        $post->setTitle('Post')->setBody('Body')->setCreatedAt(new \DateTimeImmutable());
        $post->setAuthor($authorA);
        $em->persist($post);
        $em->flush();

        // Re-assign the post from author A to author B.
        $post->setAuthor($authorB);
        $em->flush();

        $authorAEntries = $reader->createQuery(Author::class)->execute();

        $types = array_map(static fn ($e): string => $e->type, $authorAEntries);

        $this->assertContains(
            TransactionType::ASSOCIATE,
            $types,
            'author_audit must contain an ASSOCIATE entry when the post is first assigned.'
        );
        $this->assertContains(
            TransactionType::DISSOCIATE,
            $types,
            'author_audit must contain a DISSOCIATE entry when the post is re-assigned away.'
        );

        // The re-assignment to author B must also generate an ASSOCIATE entry.
        $this->assertSame(
            2,
            \count(array_filter($types, static fn (string $t): bool => TransactionType::ASSOCIATE === $t)),
            'author_audit must contain 2 ASSOCIATE entries total (one for A, one for B).'
        );
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
        ]);
    }
}
