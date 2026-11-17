<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\ConnectionTypeProvider\CustomEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\ConnectionTypeProvider\CustomStringType;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ProviderConfigurationTrait;
use DH\Auditor\Tests\Traits\AuditorTrait;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Types registered only in the type provider of a connection (DBAL 4.6+, e.g. with the
 * #[AsDbalType] attribute) are unknown to the global type registry.
 *
 * @internal
 */
#[Small]
final class ConnectionTypeProviderTest extends TestCase
{
    use AuditorTrait;
    use ProviderConfigurationTrait;

    public function testAuditsACustomTypeRegisteredOnlyInTheConnectionTypeProvider(): void
    {
        if (!method_exists(DBALConfiguration::class, 'setTypeProvider')) {
            $this->markTestSkipped('Per-connection type providers require doctrine/dbal 4.6+.');
        }

        $dbalConfiguration = new DBALConfiguration();
        $dbalConfiguration->setMiddlewares([new AuditorMiddleware()]);
        $dbalConfiguration->setTypeProvider(new TypeRegistry([
            'connection_custom_string' => CustomStringType::class,
        ]));
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $dbalConfiguration);

        $paths = [
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/ConnectionTypeProvider',
        ];
        $ormConfiguration = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $ormConfiguration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfiguration->enableNativeLazyObjects(true);
        if (method_exists($ormConfiguration, 'setUseSchemaEditorApi') && method_exists(Schema::class, 'edit')) {
            $ormConfiguration->setUseSchemaEditorApi(true);
        }

        $entityManager = new EntityManager($connection, $ormConfiguration);

        $provider = new DoctrineProvider($this->createProviderConfiguration([
            'entities' => [CustomEntity::class => ['enabled' => true]],
        ]));
        $this->createAuditor()->registerProvider($provider);
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        // Creates the entity table and, through the auditor schema listener, its audit table.
        new SchemaTool($entityManager)->createSchema([
            $entityManager->getClassMetadata(CustomEntity::class),
        ]);

        $entity = new CustomEntity(1, 'old value');
        $entityManager->persist($entity);
        $entityManager->flush();

        $entity->setValue('new value');
        $entityManager->flush();

        $audits = new Reader($provider)->createQuery(CustomEntity::class)->execute();
        $this->assertCount(2, $audits);
        $this->assertSame(TransactionType::Update->value, $audits[0]->type);
        $this->assertSame('1', $audits[0]->objectId);
        $this->assertSame([
            'value' => [
                'new' => 'new value',
                'old' => 'old value',
            ],
        ], $audits[0]->getDiffs());
        $this->assertSame(TransactionType::Insert->value, $audits[1]->type);
    }
}
