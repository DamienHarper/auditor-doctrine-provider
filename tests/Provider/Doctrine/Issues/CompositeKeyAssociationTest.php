<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\CompositeKey\Article;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\CompositeKey\ArticleTag;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\CompositeKey\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * An audited entity whose inverse-side collection holds entities with a composite primary key
 * (identity through foreign entities).
 *
 * Adding such an entity records an ASSOCIATE entry on the audited side, whose target is the
 * composite-key entity. Summarizing that target used to throw
 * "Composite primary keys are not supported", which failed the flush.
 *
 * @internal
 */
#[Small]
final class CompositeKeyAssociationTest extends TestCase
{
    use SchemaSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
    }

    public function testAssociatingACompositeKeyEntityIsAudited(): void
    {
        $em = $this->provider->getAuditingServiceForEntity(Article::class)->getEntityManager();

        $article = new Article()->setTitle('Article');
        $tag = new Tag()->setName('Tag');
        $em->persist($article);
        $em->persist($tag);
        $em->flush();

        $em->persist(new ArticleTag($article, $tag));
        $em->flush();

        $entries = new Reader($this->provider)->createQuery(Article::class, ['type' => TransactionType::ASSOCIATE])->execute();
        $this->assertCount(1, $entries);

        $target = $entries[0]->getDiffs()['target'];
        $this->assertSame(ArticleTag::class, $target['class']);
        $this->assertSame('article-tag', $target['pkName']);
        $this->assertSame($article->getId().'-'.$tag->getId(), $target['article-tag']);
        $this->assertSame('article tag', $target['label']);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Article::class => ['enabled' => true],
        ]);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration());

        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../Fixtures/CompositeKey',
            ],
            'default'
        );

        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }
}
