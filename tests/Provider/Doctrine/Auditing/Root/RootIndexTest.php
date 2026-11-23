<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\Root;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\Bundle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleCover;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\InverseSideDeletion\BundleTag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Rows of the audit root index written along with the audit entries.
 *
 * @internal
 */
#[Small]
final class RootIndexTest extends TestCase
{
    use SchemaSetupTrait;

    /**
     * @var array<class-string, array<string, mixed>>
     */
    private array $entities = [
        Bundle::class => ['enabled' => true, 'roots' => ['cover']],
        BundleItem::class => ['enabled' => true, 'roots' => ['bundle', 'bundle.cover']],
        BundleCover::class => ['enabled' => true],
        BundleTag::class => ['enabled' => true],
    ];

    public function testTheIndexTableIsCreated(): void
    {
        $tables = $this->entityManager()->getConnection()->createSchemaManager()->listTableNames();

        $this->assertContains('audit_root', $tables);
    }

    public function testEntriesAreIndexedUnderTheirRoots(): void
    {
        $em = $this->entityManager();
        $bundle = new Bundle('bundle');
        $cover = new BundleCover($bundle);
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->persist($cover);
        $em->persist($item);
        $em->flush();

        // one row per object and root, although the bundle has an insert entry and associate entries (its cover and
        // its item) in the transaction
        $this->assertSame([
            [Bundle::class, (string) $bundle->getId(), 'bundle', 'inverse_side_deletion_bundle_item_audit', (string) $item->getId()],
            [BundleCover::class, (string) $cover->getId(), 'bundle.cover', 'inverse_side_deletion_bundle_item_audit', (string) $item->getId()],
            [BundleCover::class, (string) $cover->getId(), 'cover', 'inverse_side_deletion_bundle_audit', (string) $bundle->getId()],
        ], $this->rows());
    }

    public function testUpdatingTheSchemaLeavesTheIndexTableAlone(): void
    {
        $sqls = new SchemaManager($this->provider)->getUpdateAuditSchemaSql();

        $this->assertStringNotContainsString('audit_root', json_encode($sqls, JSON_THROW_ON_ERROR), 'No false diff on the index table.');
    }

    public function testTheOwnerOfAnInverseOneToOneIsFoundAmongTheScheduledEntities(): void
    {
        $em = $this->entityManager();
        $bundle = new Bundle('bundle');
        $cover = new BundleCover($bundle);
        $bundle->setCover(null); // only the owning side knows the association
        $em->persist($bundle);
        $em->persist($cover);
        $em->flush();

        $this->assertContains([BundleCover::class, (string) $cover->getId(), 'cover', 'inverse_side_deletion_bundle_audit', (string) $bundle->getId()], $this->rows());
    }

    public function testRemovedEntitiesAreIndexed(): void
    {
        $em = $this->entityManager();
        $bundle = new Bundle('bundle');
        $item = new BundleItem($bundle, 'item');
        $em->persist($bundle);
        $em->flush();

        $itemId = (string) $item->getId();

        $em->remove($item);
        $em->flush();

        $removals = array_filter($this->rows(), static fn (array $row): bool => 'inverse_side_deletion_bundle_item_audit' === $row[3] && $itemId === $row[4]);
        $this->assertCount(2, $removals, 'The insertion and the removal of the item are both indexed under the bundle.');
    }

    public function testRootResolver(): void
    {
        $this->provider->getConfiguration()->setRootResolver(static fn (object $entity): array => $entity instanceof BundleTag
            ? ['first_bundle' => $entity->getBundles()->first() ?: null]
            : []);

        $em = $this->entityManager();
        $bundle = new Bundle('bundle');
        $tag = new BundleTag();
        $bundle->addTag($tag);
        $em->persist($bundle);
        $em->persist($tag);
        $em->flush();

        $this->assertContains([Bundle::class, (string) $bundle->getId(), 'first_bundle', 'inverse_side_deletion_bundle_tag_audit', (string) $tag->getId()], $this->rows());
    }

    public function testARootWithoutIdentifierIsSkipped(): void
    {
        $this->provider->getConfiguration()->setRootResolver(static fn (object $entity): array => $entity instanceof BundleTag
            ? ['detached' => new Bundle('never persisted')]
            : []);

        $em = $this->entityManager();
        $em->persist(new BundleTag());
        $em->flush();

        $this->assertSame([], array_filter($this->rows(), static fn (array $row): bool => 'detached' === $row[2]));
    }

    public function testTheRootResolverMustReturnRootsByPath(): void
    {
        $this->provider->getConfiguration()->setRootResolver(static fn (object $entity): mixed => $entity instanceof BundleTag ? 'not an array' : []);

        $em = $this->entityManager();
        $em->persist(new BundleTag());

        $this->expectException(\UnexpectedValueException::class);

        $em->flush();
    }

    public function testTheRootResolverMustReturnEntities(): void
    {
        $this->provider->getConfiguration()->setRootResolver(static fn (object $entity): array => $entity instanceof BundleTag ? ['path' => 'not an entity'] : []);

        $em = $this->entityManager();
        $em->persist(new BundleTag());

        $this->expectException(\UnexpectedValueException::class);

        $em->flush();
    }

    public function testACollectionCannotBeARoot(): void
    {
        $this->provider->getConfiguration()->setEntities([Bundle::class => ['enabled' => true, 'roots' => ['items']]] + $this->entities);

        $em = $this->entityManager();
        $em->persist(new Bundle('bundle'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('items');

        $em->flush();
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function rows(): array
    {
        $rows = $this->entityManager()->getConnection()->fetchAllNumeric('SELECT root_class, root_id, root_path, audit_table, object_id FROM audit_root ORDER BY root_path, audit_table, object_id, id');

        return array_map(static fn (array $row): array => array_map(strval(...), $row), $rows);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Bundle::class)->getEntityManager();
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration());

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../../Fixtures/InverseSideDeletion',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities($this->entities);
    }
}
