<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\Attribute;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\AttributeLoader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\ClassLevelAttributesEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\DiffLabelEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\DummyCategoryResolver;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\RetentionEntity;
use DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\ClassLevelWithoutField\ClassLevelIgnoreWithoutFieldEntity;
use DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\PropertyLevelWithField\PropertyLevelDiffLabelWithFieldEntity;
use DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\Retention\InvalidRetentionEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\EntityManagerInterfaceTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class AttributeLoaderTest extends TestCase
{
    use EntityManagerInterfaceTrait;

    public function testLoadEntitiesWithoutAnnotationAndAttribute(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Traits',
            ],
            'default'
        );

        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();
        $this->assertCount(0, $loaded, 'No annotation loaded using attribute driver');
    }

    public function testLoadEntitiesWithAttributesOnly(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();
        $this->assertCount(5, $loaded);
    }

    public function testDiffLabelResolversAreLoaded(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();

        $this->assertArrayHasKey(DiffLabelEntity::class, $loaded);
        $this->assertArrayHasKey('diff_label_resolvers', $loaded[DiffLabelEntity::class]);
        $this->assertSame(
            ['categoryId' => DummyCategoryResolver::class],
            $loaded[DiffLabelEntity::class]['diff_label_resolvers']
        );
    }

    public function testEntitiesWithoutDiffLabelHaveEmptyResolvers(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();

        foreach ($loaded as $entityClass => $config) {
            if (!\in_array($entityClass, [DiffLabelEntity::class, ClassLevelAttributesEntity::class], true)) {
                $this->assertArrayHasKey('diff_label_resolvers', $config, $entityClass.' must have diff_label_resolvers key');
                $this->assertSame([], $config['diff_label_resolvers'], $entityClass.' must have empty diff_label_resolvers');
            }
        }
    }

    public function testRetentionAttributesAreLoaded(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();

        $this->assertArrayHasKey(RetentionEntity::class, $loaded);
        $this->assertSame('P7Y', $loaded[RetentionEntity::class]['max_age']);
        $this->assertSame(1_000, $loaded[RetentionEntity::class]['max_entries']);
    }

    public function testEntitiesWithoutRetentionHaveNullValues(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();

        foreach ($loaded as $entityClass => $config) {
            if (RetentionEntity::class !== $entityClass) {
                $this->assertArrayHasKey('max_age', $config, $entityClass.' must have max_age key');
                $this->assertArrayHasKey('max_entries', $config, $entityClass.' must have max_entries key');
                $this->assertNull($config['max_age'], $entityClass.' must have null max_age');
                $this->assertNull($config['max_entries'], $entityClass.' must have null max_entries');
            }
        }
    }

    public function testInvalidRetentionAttributeFailsWithTheEntityName(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../InvalidFixtures/Retention',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Invalid #[Auditable] attribute on "%s": Invalid retention "maxEntries" value 0', InvalidRetentionEntity::class));

        $attributeLoader->load();
    }

    public function testClassLevelIgnoreAndDiffLabelAreLoaded(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $loaded = new AttributeLoader($entityManager)->load();

        $this->assertArrayHasKey(ClassLevelAttributesEntity::class, $loaded);
        $this->assertSame(['secret', 'token', 'note'], $loaded[ClassLevelAttributesEntity::class]['ignored_columns']);
        $this->assertSame(['categoryId' => DummyCategoryResolver::class], $loaded[ClassLevelAttributesEntity::class]['diff_label_resolvers']);
    }

    public function testClassLevelAttributeWithoutFieldFails(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../InvalidFixtures/ClassLevelWithoutField',
            ],
            'default'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Invalid #[Ignore] attribute on "%s": a class-level #[Ignore] needs a "field" argument.', ClassLevelIgnoreWithoutFieldEntity::class));

        new AttributeLoader($entityManager)->load();
    }

    public function testPropertyLevelAttributeWithFieldFails(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/../../InvalidFixtures/PropertyLevelWithField',
            ],
            'default'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Invalid #[DiffLabel] attribute on "%s::$categoryId": leave "field" out on a property, the property is the field.', PropertyLevelDiffLabelWithFieldEntity::class));

        new AttributeLoader($entityManager)->load();
    }
}
