<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class FullSnapshotOnRemoveTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testRemoveEntryHoldsTheStateOfTheRemovedEntity(): void
    {
        $entityManager = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager();

        $post = new Post();
        $post->setTitle('First');
        $post->setBody('Body');
        $post->setCreatedAt(new \DateTimeImmutable('2026-11-22 12:00:00'));
        $entityManager->persist($post);
        $entityManager->flush();

        $entityManager->remove($post);
        $entityManager->flush();

        $audits = $this->createReader()->createQuery(Post::class)->execute();
        $this->assertSame('remove', $audits[0]->type);

        $diffs = $audits[0]->getDiffs();
        $this->assertSame(['new' => null, 'old' => 'First'], $diffs['title']);
        $this->assertSame(['new' => null, 'old' => 'Body'], $diffs['body']);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['full_snapshot_on_remove' => true]));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([Post::class => ['enabled' => true]]);
    }
}
