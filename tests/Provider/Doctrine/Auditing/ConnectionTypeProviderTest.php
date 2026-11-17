<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Provider\Doctrine\Auditing\Transaction\TransactionProcessor;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\ConnectionTypeProvider\CustomEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\ConnectionTypeProvider\CustomStringType;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ProviderConfigurationTrait;
use DH\Auditor\Tests\Traits\AuditorTrait;
use DH\Auditor\Tests\Traits\ReflectionTrait;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class ConnectionTypeProviderTest extends TestCase
{
    use AuditorTrait;
    use ProviderConfigurationTrait;
    use ReflectionTrait;

    public function testAuditsACustomTypeRegisteredOnlyInTheConnectionTypeProvider(): void
    {
        $dbalConfiguration = new DBALConfiguration();
        $dbalConfiguration->setTypeProvider(new TypeRegistry([
            'connection_custom_string' => CustomStringType::class,
        ]));
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $dbalConfiguration);

        $ormConfiguration = ORMSetup::createAttributeMetadataConfig([
            __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../Fixtures/ConnectionTypeProvider',
        ], true);
        $ormConfiguration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfiguration->enableNativeLazyObjects(true);
        $ormConfiguration->setUseSchemaEditorApi(true);

        $entityManager = new EntityManager($connection, $ormConfiguration);

        $provider = new DoctrineProvider($this->createProviderConfiguration([
            'entities' => [CustomEntity::class => ['enabled' => true]],
        ]));
        $this->createAuditor()->registerProvider($provider);
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        new SchemaTool($entityManager)->createSchema([
            $entityManager->getClassMetadata(CustomEntity::class),
        ]);
        new SchemaManager($provider)->updateAuditSchema();

        $entity = new CustomEntity(1, 'new value');
        $processor = new TransactionProcessor($provider);
        $blame = $this->reflectMethod(TransactionProcessor::class, 'blame')->invoke($processor);
        $this->reflectMethod(TransactionProcessor::class, 'preComputeFlushContext')->invoke($processor, $blame);
        $this->reflectMethod(TransactionProcessor::class, 'update')->invoke(
            $processor,
            $entityManager,
            $entity,
            ['value' => ['old value', 'new value']],
            '01ABCDEFGHJKMNPQRSTVWXYZ00',
        );

        $audits = new Reader($provider)->createQuery(CustomEntity::class)->execute();
        $this->assertCount(1, $audits);
        $this->assertSame([
            'value' => [
                'new' => 'new value',
                'old' => 'old value',
            ],
        ], $audits[0]->getDiffs());
    }
}
