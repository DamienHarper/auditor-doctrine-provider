<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Event;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * NestedFlushTest with audit entries written with multi-row INSERT statements (`batch_size` option): batches
 * smaller than the outer transactions, so that some are written before the COMMIT.
 *
 * @see NestedFlushTestsTrait
 *
 * @internal
 */
#[Small]
final class NestedFlushBatchInsertTest extends TestCase
{
    use NestedFlushTestsTrait;
    use SchemaSetupTrait;

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['batch_size' => 2]));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
        ]);
    }
}
