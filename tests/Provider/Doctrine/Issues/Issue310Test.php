<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Model\Entry;
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
            $post->setTitle('Post '.$i)->setBody('Body')->setCreatedAt(new \DateTimeImmutable());
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
     * A single entity flushed with a ManyToOne field set must also produce exactly one
     * ASSOCIATE entry on the inverse side's audit table (basic single-entity case).
     */
    public function testAssociateEntryIsRecordedForSingleEntityFlush(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $reader = new Reader($this->provider);

        $author = new Author();
        $author->setFullname('John Doe')->setEmail('john@example.com');
        $em->persist($author);
        $em->flush();

        $post = new Post();
        $post->setTitle('Post 1')->setBody('Body')->setCreatedAt(new \DateTimeImmutable());
        $post->setAuthor($author);
        $em->persist($post);
        $em->flush();

        $authorEntries = $reader->createQuery(Author::class, ['type' => TransactionType::ASSOCIATE])->execute();

        $this->assertCount(
            1,
            $authorEntries,
            'author_audit must contain exactly one ASSOCIATE entry when a single post is flushed.'
        );
    }

    /**
     * When a ManyToOne field is re-assigned (old → new target), the old target must receive a
     * DISSOCIATE entry and the new target must receive an ASSOCIATE entry — verified per author.
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

        // Author A: must have one ASSOCIATE (initial assignment) and one DISSOCIATE (re-assignment away).
        $typesA = array_map(
            static fn (Entry $e): string => $e->type,
            $reader->createQuery(Author::class, ['object_id' => $authorA->getId()])->execute(),
        );

        $this->assertContains(
            TransactionType::ASSOCIATE,
            $typesA,
            'author_A_audit must contain an ASSOCIATE entry when the post is first assigned.'
        );
        $this->assertCount(
            1,
            array_filter($typesA, static fn (string $t): bool => TransactionType::DISSOCIATE === $t),
            'author_A_audit must contain exactly one DISSOCIATE entry when the post is re-assigned away.'
        );

        // Author B: must have one ASSOCIATE (re-assignment to B) and no DISSOCIATE.
        $typesB = array_map(
            static fn (Entry $e): string => $e->type,
            $reader->createQuery(Author::class, ['object_id' => $authorB->getId()])->execute(),
        );

        $this->assertContains(
            TransactionType::ASSOCIATE,
            $typesB,
            'author_B_audit must contain an ASSOCIATE entry when the post is re-assigned to it.'
        );
        $this->assertCount(
            0,
            array_filter($typesB, static fn (string $t): bool => TransactionType::DISSOCIATE === $t),
            'author_B_audit must not contain any DISSOCIATE entry.'
        );
    }

    /**
     * When the inverse-side entity (Author) is not audited, hydrateInverseSideAssociations()
     * must skip it silently — no exception, no stray audit entry.
     */
    public function testNoAssociateEntryIsWrittenWhenInverseSideEntityIsNotAudited(): void
    {
        // Author is registered so its audit table exists, but disabled so isAudited() returns
        // false — exercising the guard in hydrateInverseSideAssociations().
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => false],
            Post::class => ['enabled' => true],
        ]);

        $em = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $reader = new Reader($this->provider);

        $author = new Author();
        $author->setFullname('John Doe')->setEmail('john@example.com');
        $em->persist($author);

        $post = new Post();
        $post->setTitle('Post 1')->setBody('Body')->setCreatedAt(new \DateTimeImmutable());
        $post->setAuthor($author);
        $em->persist($post);
        $em->flush();

        $authorEntries = $reader->createQuery(Author::class, ['type' => TransactionType::ASSOCIATE])->execute();

        $this->assertCount(
            0,
            $authorEntries,
            'No ASSOCIATE entry must be written to author_audit when Author is not audited.'
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
