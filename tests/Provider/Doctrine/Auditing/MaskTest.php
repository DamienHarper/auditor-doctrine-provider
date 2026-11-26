<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Attribute\Mask;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Fixtures\SimpleServiceLocator;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\DummyCategoryResolver;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\MaskEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests of #[Mask]: the change of a masked field is recorded without its values.
 *
 * @internal
 */
#[Small]
final class MaskTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    private const string SECRET = 'a-mask-secret-of-at-least-32-bytes';

    public function testInsertMasksTheValues(): void
    {
        $this->persist($this->createEntity());

        $diffs = $this->createReader()->createQuery(MaskEntity::class)->execute()[0]->getDiffs();

        $this->assertSame(['new' => 'John', 'old' => null], $diffs['name']);
        $this->assertSame(['new' => hash_hmac('sha256', 'john@example.com', self::SECRET), 'old' => null], $diffs['email']);
        $this->assertSame(['new' => Mask::HIDDEN_VALUE, 'old' => null], $diffs['pin']);
    }

    public function testUpdateRecordsAChangeOfHiddenValues(): void
    {
        $entity = $this->createEntity();
        $this->persist($entity);

        $entity->pin = '5678';
        $entity->email = null;
        $this->persist($entity);

        $diffs = $this->createReader()->createQuery(MaskEntity::class)->execute()[0]->getDiffs();

        $this->assertSame(['new' => Mask::HIDDEN_VALUE, 'old' => Mask::HIDDEN_VALUE], $diffs['pin']);
        $this->assertSame(['new' => null, 'old' => hash_hmac('sha256', 'john@example.com', self::SECRET)], $diffs['email']);
        $this->assertArrayNotHasKey('name', $diffs);
    }

    public function testMaskedFieldHasNoDiffLabel(): void
    {
        $this->provider->setDiffLabelResolverLocator(new SimpleServiceLocator([
            DummyCategoryResolver::class => static fn (): DummyCategoryResolver => new DummyCategoryResolver(),
        ]));

        $this->persist($this->createEntity());

        $diffs = $this->createReader()->createQuery(MaskEntity::class)->execute()[0]->getDiffs();

        $this->assertSame(['new' => Mask::HIDDEN_VALUE, 'old' => null], $diffs['categoryId']);
    }

    public function testRemovalSnapshotMasksTheValues(): void
    {
        $entity = $this->createEntity();
        $this->persist($entity);

        $entityManager = $this->provider->getStorageServiceForEntity(MaskEntity::class)->getEntityManager();
        $entityManager->remove($entity);
        $entityManager->flush();

        $diffs = $this->createReader()->createQuery(MaskEntity::class)->execute()[0]->getDiffs();

        $this->assertSame(['new' => null, 'old' => 'John'], $diffs['name']);
        $this->assertSame(['new' => null, 'old' => hash_hmac('sha256', 'john@example.com', self::SECRET)], $diffs['email']);
        $this->assertSame(['new' => null, 'old' => Mask::HIDDEN_VALUE], $diffs['pin']);
    }

    private function createEntity(): MaskEntity
    {
        $entity = new MaskEntity();
        $entity->name = 'John';
        $entity->email = 'john@example.com';
        $entity->pin = '1234';
        $entity->categoryId = 1;

        return $entity;
    }

    private function persist(MaskEntity $entity): void
    {
        $entityManager = $this->provider->getStorageServiceForEntity(MaskEntity::class)->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration([
            'mask_secret' => self::SECRET,
            'full_snapshot_on_remove' => true,
        ]));
        $auditor->registerProvider($this->provider);

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/Entity/Attribute',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            MaskEntity::class => ['enabled' => true],
        ]);
    }
}
