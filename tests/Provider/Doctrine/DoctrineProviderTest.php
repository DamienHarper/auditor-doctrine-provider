<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Exception\ProviderException;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\AttributeLoader;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\Service\StorageServiceInterface;
use DH\Auditor\Security\RoleCheckerInterface;
use DH\Auditor\Security\SecurityProviderInterface;
use DH\Auditor\Tests\Fixtures\Provider\AuditNoStorageProvider;
use DH\Auditor\Tests\Fixtures\Provider\StorageNoAuditProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\AuditableButUnauditedEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\AuditedEntity as AuditedEntityWithAttribute;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\ClassLevelAttributesEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\RetentionEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\DoctrineProviderTrait;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
#[Small]
final class DoctrineProviderTest extends TestCase
{
    use DoctrineProviderTrait;

    public function testRegisterStorageServiceAgainstNoStorageProvider(): void
    {
        $provider = new AuditNoStorageProvider();

        $this->expectException(ProviderException::class);
        $provider->registerStorageService(new StorageService('storageEM_1', $this->createEntityManager()));
    }

    public function testRegisterAuditingServiceAgainstNoAuditingProvider(): void
    {
        $provider = new StorageNoAuditProvider();

        $this->expectException(ProviderException::class);
        $provider->registerAuditingService(new AuditingService('auditingEM_1', $this->createEntityManager()));
    }

    public function testTheLoggerDiscardsTheRecordsUntilOneIsSet(): void
    {
        $provider = new DoctrineProvider($this->createProviderConfiguration());
        $this->assertInstanceOf(NullLogger::class, $provider->getLogger());

        $logger = $this->createStub(LoggerInterface::class);
        $provider->setLogger($logger);
        $this->assertSame($logger, $provider->getLogger());
    }

    public function testRegisterStorageEntityManager(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        $this->assertCount(0, $provider->getStorageServices(), 'There is no storage entity manager registered.');

        $provider->registerStorageService(new StorageService('storageEM_1', $this->createEntityManager()));
        $this->assertCount(1, $provider->getStorageServices(), 'There is 1 storage entity manager registered.');

        $provider->registerStorageService(new StorageService('storageEM_2', $this->createEntityManager()));
        $this->assertCount(2, $provider->getStorageServices(), 'There are 2 storage entity managers registered.');

        $this->expectException(ProviderException::class);
        $provider->registerStorageService(new StorageService('storageEM_1', $this->createEntityManager()));
    }

    public function testRegisterAuditingEntityManager(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        $this->assertCount(0, $provider->getAuditingServices(), 'There is no auditing entity manager registered.');

        $provider->registerAuditingService(new AuditingService('auditingEM_1', $this->createEntityManager()));
        $this->assertCount(1, $provider->getAuditingServices(), 'There is 1 auditing entity manager registered.');

        $provider->registerAuditingService(new AuditingService('auditingEM_2', $this->createEntityManager()));
        $this->assertCount(2, $provider->getAuditingServices(), 'There are 2 auditing entity managers registered.');

        $this->expectException(ProviderException::class);
        $provider->registerAuditingService(new AuditingService('auditingEM_1', $this->createEntityManager()));
    }

    #[Depends('testRegisterAuditingEntityManager')]
    public function testGetAuditingServiceForEntity(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();
        $provider->registerAuditingService(new AuditingService('auditingEM', $this->createEntityManager()));

        $this->expectException(InvalidArgumentException::class);
        $provider->getAuditingServiceForEntity('My\Fake\Entity');
    }

    #[Depends('testRegisterAuditingEntityManager')]
    #[Depends('testRegisterStorageEntityManager')]
    public function testRegisterEntityManager(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        $this->assertCount(0, $provider->getAuditingServices(), 'There is no auditing entity manager registered.');
        $this->assertCount(0, $provider->getStorageServices(), 'There is no storage entity manager registered.');

        $provider->registerAuditingService(new AuditingService('auditingEM', $this->createEntityManager([
            __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/Fixtures/Entity/Standard/Blog',
        ])));

        $this->assertCount(1, $provider->getAuditingServices(), 'There is 1 auditing entity manager registered.');
        $this->assertCount(0, $provider->getStorageServices(), 'There is no storage entity manager registered.');

        $provider->registerStorageService(new StorageService('storageEM', $this->createEntityManager([
            __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/Fixtures/Entity/Standard/Blog',
        ])));

        $this->assertCount(1, $provider->getAuditingServices(), 'There is 1 auditing entity manager registered.');
        $this->assertCount(1, $provider->getStorageServices(), 'There is 1 storage entity manager registered.');

        $entityManager = $this->createEntityManager();
        $provider->registerAuditingService(new AuditingService('default', $entityManager));
        $provider->registerStorageService(new StorageService('default', $entityManager));

        $this->assertCount(2, $provider->getAuditingServices(), 'There are 2 auditing entity managers registered.');
        $this->assertCount(2, $provider->getStorageServices(), 'There are 2 storage entity managers registered.');
    }

    public function testIsStorageMapperRequired(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        $this->assertFalse($provider->isStorageMapperRequired(), 'Mapper is not required since there is strictly less than 2 storage entity manager.');

        $entityManager = $this->createEntityManager();
        $provider->registerAuditingService(new AuditingService('EM1', $entityManager));
        $provider->registerStorageService(new StorageService('EM1', $entityManager));

        $this->assertFalse($provider->isStorageMapperRequired(), 'Mapper is not required since there is strictly less than 2 storage entity manager.');

        $entityManager = $this->createEntityManager();
        $provider->registerAuditingService(new AuditingService('EM2', $entityManager));
        $provider->registerStorageService(new StorageService('EM2', $entityManager));

        $this->assertTrue($provider->isStorageMapperRequired(), 'Mapper is required since there is more than 2 storage entity managers.');
    }

    public function testSetStorageMapper(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        // register 2 entity managers for storage (at least)
        $entityManager1 = $this->createEntityManager();
        $provider->registerAuditingService(new AuditingService('EM1', $entityManager1));
        $provider->registerStorageService(new StorageService('EM1', $entityManager1));

        $entityManager2 = $this->createEntityManager();
        $provider->registerAuditingService(new AuditingService('EM2', $entityManager2));
        $provider->registerStorageService(new StorageService('EM2', $entityManager2));

        $this->assertNull($provider->getConfiguration()->getStorageMapper(), 'StorageMapper is not set.');

        $provider->setStorageMapper(static fn (string $entity, array $storageServices): StorageServiceInterface => 0 === mb_strpos($entity, 'Foo') ? $storageServices['EM1'] : $storageServices['EM2']);
        $this->assertNotNull($provider->getConfiguration()->getStorageMapper(), 'StorageMapper is set.');
        $this->assertIsCallable($provider->getConfiguration()->getStorageMapper(), 'StorageMapper is a callable.');

        $this->assertSame($entityManager1, $provider->getStorageServiceForEntity('Foo1')->getEntityManager(), 'EM1 is used.');
        $this->assertSame($entityManager1, $provider->getStorageServiceForEntity('Foo2')->getEntityManager(), 'EM1 is used.');
        $this->assertSame($entityManager2, $provider->getStorageServiceForEntity('Bar1')->getEntityManager(), 'EM2 is used.');
        $this->assertSame($entityManager2, $provider->getStorageServiceForEntity('Bar2')->getEntityManager(), 'EM2 is used.');

        $provider->setStorageMapper(new FakeStorageMapper());
        $this->assertNotNull($provider->getConfiguration()->getStorageMapper(), 'StorageMapper is set.');
        $this->assertIsCallable($provider->getConfiguration()->getStorageMapper(), 'StorageMapper is a callable.');

        $this->assertSame($entityManager1, $provider->getStorageServiceForEntity('Foo1')->getEntityManager(), 'EM1 is used.');
        $this->assertSame($entityManager1, $provider->getStorageServiceForEntity('Foo2')->getEntityManager(), 'EM1 is used.');
        $this->assertSame($entityManager2, $provider->getStorageServiceForEntity('Bar1')->getEntityManager(), 'EM2 is used.');
        $this->assertSame($entityManager2, $provider->getStorageServiceForEntity('Bar2')->getEntityManager(), 'EM2 is used.');
    }

    public function testCheckStorageMapperThrowsExceptionWhenNoMapperDefined(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();

        // register 2 entity managers for storage (at least)
        $entityManager1 = $this->createEntityManager();
        $provider->registerStorageService(new StorageService('EM1', $entityManager1));

        $entityManager2 = $this->createEntityManager();
        $provider->registerStorageService(new StorageService('EM2', $entityManager2));

        $this->expectException(ProviderException::class);
        $provider->getStorageServiceForEntity(DummyEntity::class);
    }

    public function testIsRegistered(): void
    {
        // unregistered provider
        $provider = $this->createUnregisteredDoctrineProvider();
        $this->assertFalse($provider->isRegistered(), 'Provider is not registered.');

        $this->expectException(\Exception::class);
        $provider->getAuditor();

        // registered provider
        $provider = $this->createDoctrineProvider();
        $this->assertTrue($provider->isRegistered(), 'Provider is registered.');
    }

    public function testIsAudited(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is audited.');
        $this->assertFalse($provider->isAudited(Comment::class), 'Entity "'.Comment::class.'" is not audited.');
    }

    public function testIsAuditable(): void
    {
        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertFalse($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is not audited.');
        $this->assertTrue($provider->isAuditable(Post::class), 'Entity "'.Post::class.'" is auditable.');
        $this->assertFalse($provider->isAudited(Comment::class), 'Entity "'.Comment::class.'" is not audited.');
        $this->assertFalse($provider->isAuditable(Comment::class), 'Entity "'.Comment::class.'" is not auditable.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedHonorsEnabledFlag(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertFalse($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is not audited.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedWhenAuditIsEnabled(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);
        $provider->getAuditor()->getConfiguration()->enable();

        $this->assertTrue($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);
        $provider->getAuditor()->getConfiguration()->enable();

        $this->assertFalse($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is not audited.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedWhenAuditIsDisabled(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is audited.');

        $provider->getAuditor()->getConfiguration()->disable();

        $this->assertFalse($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is not audited.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedWhenAuditingIsDisabledForTheProvider(): void
    {
        $configuration = $this->createProviderConfiguration([
            'entities' => [
                Post::class => [
                    'enabled' => true,
                ],
            ],
        ]);
        $provider = $this->createDoctrineProvider($configuration);
        $auditor = $provider->getAuditor();
        $auditor->registerProvider(new AuditNoStorageProvider());

        $auditor->disableAuditing($provider);

        $this->assertFalse($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is not audited by a provider whose auditing is disabled.');

        $auditor->enableAuditing($provider);

        $this->assertTrue($provider->isAudited(Post::class), 'Entity "'.Post::class.'" is audited again once auditing is enabled.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedWhenTheProviderIsNotRegistered(): void
    {
        $configuration = $this->createProviderConfiguration([
            'entities' => [
                Post::class => [
                    'enabled' => true,
                ],
            ],
        ]);
        $provider = new DoctrineProvider($configuration);
        $provider->setAuditor($this->createAuditor());

        $this->assertTrue($provider->isAudited(Post::class), 'An auditor that does not know the provider leaves auditing on.');
    }

    #[Depends('testIsAudited')]
    public function testIsAuditedFieldAuditsAnyFieldByDefault(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAuditedField(Post::class, 'id'), 'Every field is audited.');
        $this->assertTrue($provider->isAuditedField(Post::class, 'title'), 'Every field is audited.');
        $this->assertTrue($provider->isAuditedField(Post::class, 'created_at'), 'Every field is audited.');
        $this->assertTrue($provider->isAuditedField(Post::class, 'updated_at'), 'Every field is audited.');
    }

    #[Depends('testIsAuditedFieldAuditsAnyFieldByDefault')]
    public function testIsAuditedFieldHonorsLocallyIgnoredColumns(): void
    {
        $entities = [
            Post::class => [
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ],
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAuditedField(Post::class, 'id'), 'Field "'.Post::class.'::$id" is audited.');
        $this->assertTrue($provider->isAuditedField(Post::class, 'title'), 'Field "'.Post::class.'::$title" is audited.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'created_at'), 'Field "'.Post::class.'::$created_at" is not audited.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'updated_at'), 'Field "'.Post::class.'::$updated_at" is not audited.');
    }

    #[Depends('testIsAuditedFieldHonorsLocallyIgnoredColumns')]
    public function testIsAuditedFieldHonorsGloballyIgnoredColumns(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->createProviderConfiguration([
            'ignored_columns' => [
                'created_at',
                'updated_at',
            ],
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAuditedField(Post::class, 'id'), 'Field "'.Post::class.'::$id" is audited.');
        $this->assertTrue($provider->isAuditedField(Post::class, 'title'), 'Field "'.Post::class.'::$title" is audited.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'created_at'), 'Field "'.Post::class.'::$created_at" is not audited.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'updated_at'), 'Field "'.Post::class.'::$updated_at" is not audited.');
    }

    #[Depends('testIsAuditedFieldHonorsLocallyIgnoredColumns')]
    public function testIsAuditedFieldReturnsFalseIfEntityIsNotAudited(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->createProviderConfiguration([
            'ignored_columns' => [
                'created_at',
                'updated_at',
            ],
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertFalse($provider->isAuditedField(Comment::class, 'id'), 'Field "'.Comment::class.'::$id" is audited but "'.Comment::class.'" entity is not.');
    }

    public function testLoadEntitiesWithAttributesOnly(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $attributeLoader = new AttributeLoader($entityManager);
        $loaded = $attributeLoader->load();
        $this->assertCount(5, $loaded);

        $auditor = $this->createAuditor();
        $provider = new DoctrineProvider($this->createProviderConfiguration(['entities' => $loaded]));
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($provider);

        $this->assertTrue($provider->isAudited(AuditedEntityWithAttribute::class), '"'.AuditedEntityWithAttribute::class.'" is audited.');
        $this->assertTrue($provider->isAuditedField(AuditedEntityWithAttribute::class, 'auditedField'), 'Field "'.AuditedEntityWithAttribute::class.'::$auditedField" is audited.');
        $this->assertFalse($provider->isAuditedField(AuditedEntityWithAttribute::class, 'ignoredField'), 'Field "'.AuditedEntityWithAttribute::class.'::$ignoredField" is ignored.');
        $this->assertFalse($provider->isAuditedField(AuditedEntityWithAttribute::class, 'ignoredProtectedField'), 'Field "'.AuditedEntityWithAttribute::class.'::$ignoredProtectedField" is ignored.');
        $this->assertFalse($provider->isAuditedField(AuditedEntityWithAttribute::class, 'ignoredPrivateField'), 'Field "'.AuditedEntityWithAttribute::class.'::$ignoredPrivateField" is ignored.');
        $this->assertTrue($provider->isAuditedField(AuditedEntityWithAttribute::class, 'id'), 'Field "'.AuditedEntityWithAttribute::class.'::$id" is audited.');

        // #[Ignore(field: …)] on the class
        $this->assertFalse($provider->isAuditedField(ClassLevelAttributesEntity::class, 'secret'));
        $this->assertFalse($provider->isAuditedField(ClassLevelAttributesEntity::class, 'token'));
        $this->assertFalse($provider->isAuditedField(ClassLevelAttributesEntity::class, 'note'));
        $this->assertTrue($provider->isAuditedField(ClassLevelAttributesEntity::class, 'categoryId'));
    }

    public function testConfiguredRetentionOverridesTheAttributeKeyByKey(): void
    {
        // RetentionEntity carries #[Auditable(maxAge: 'P7Y', maxEntries: 1_000)]
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'max_age' => 'P2Y']);

        // The configured value wins, the other one comes from the attribute.
        $this->assertSame('P2Y', $options['max_age']);
        $this->assertSame(1_000, $options['max_entries']);
        // Other options still come from the attribute.
        $this->assertArrayHasKey('ignored_columns', $options);
    }

    public function testConfiguredNullRetentionDisablesTheAttributeValue(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'max_entries' => null]);

        $this->assertSame('P7Y', $options['max_age']);
        $this->assertNull($options['max_entries']);
    }

    public function testAttributeRetentionIsKeptWithoutConfiguredRetention(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true]);

        $this->assertSame('P7Y', $options['max_age']);
        $this->assertSame(1_000, $options['max_entries']);
    }

    public function testConfiguredRolesApplyToAnAuditableEntityWithoutSecurityAttribute(): void
    {
        // RetentionEntity carries #[Auditable] but no #[Security]
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'roles' => ['view' => ['ROLE_ADMIN']]]);

        $this->assertSame(['view' => ['ROLE_ADMIN']], $options['roles']);
    }

    public function testConfiguredRolesTakePrecedenceOverTheSecurityAttribute(): void
    {
        // AuditableButUnauditedEntity carries #[Security(view: ['ROLE1', 'ROLE2'])]
        $options = $this->loadAttributeEntityWith(['roles' => ['view' => ['ROLE_SUPER_ADMIN']]], AuditableButUnauditedEntity::class);

        $this->assertSame(['view' => ['ROLE_SUPER_ADMIN']], $options['roles']);
    }

    public function testSecurityAttributeIsKeptWithoutConfiguredRoles(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'roles' => null], AuditableButUnauditedEntity::class);

        $this->assertSame(['view' => ['ROLE1', 'ROLE2']], $options['roles']);
    }

    public function testEmptyConfiguredRolesKeepTheSecurityAttribute(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'roles' => []], AuditableButUnauditedEntity::class);

        $this->assertSame(['view' => ['ROLE1', 'ROLE2']], $options['roles']);
    }

    public function testConfiguredRolesWithoutTheViewScopeKeepTheSecurityViewRoles(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'roles' => ['View' => ['ROLE_ADMIN']]], AuditableButUnauditedEntity::class);

        $this->assertSame(['ROLE1', 'ROLE2'], $options['roles']['view'] ?? null, 'A misspelled scope does not lift the restriction.');
    }

    public function testEntityDisabledInTheConfigurationStaysDisabledDespiteTheAttribute(): void
    {
        // RetentionEntity carries #[Auditable], enabled by default
        $options = $this->loadAttributeEntityWith(['enabled' => false]);

        $this->assertFalse($options['enabled']);
    }

    public function testConfiguredEnabledDoesNotReEnableADisabledAuditableEntity(): void
    {
        // AuditableButUnauditedEntity carries #[Auditable(enabled: false)]; auditor-bundle sets enabled: true by default
        $options = $this->loadAttributeEntityWith(['enabled' => true], AuditableButUnauditedEntity::class);

        $this->assertFalse($options['enabled']);
    }

    public function testDisabledAndRestrictedInTheConfigurationAreHonouredByTheProvider(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $auditor = $this->createAuditor();
        $provider = new DoctrineProvider($this->createProviderConfiguration([
            'entities' => [
                AuditedEntityWithAttribute::class => ['enabled' => false],
                RetentionEntity::class => ['enabled' => true, 'roles' => ['view' => ['ROLE_ADMIN']]],
            ],
        ]));
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($provider);

        $this->assertFalse($provider->isAudited(AuditedEntityWithAttribute::class), 'Disabled in the configuration.');
        $this->assertTrue($provider->isAudited(RetentionEntity::class));

        $configuration = $provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);
        $options = $configuration->getEntities()[RetentionEntity::class];
        \assert(\is_array($options));
        $this->assertSame(['view' => ['ROLE_ADMIN']], $options['roles']);
    }

    public function testAuditableEntityDisabledInTheConfigurationGetsNoAuditTableWhenDisabledEntitiesAreSkipped(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $auditor = $this->createAuditor();
        $provider = new DoctrineProvider($this->createProviderConfiguration([
            'skip_disabled_entities' => true,
            'entities' => [
                AuditedEntityWithAttribute::class => ['enabled' => false],
            ],
        ]));
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($provider);

        $this->assertFalse($provider->hasAuditTable(AuditedEntityWithAttribute::class), 'Disabled in the configuration despite #[Auditable].');
        $this->assertTrue($provider->hasAuditTable(RetentionEntity::class));
    }

    public function testConfiguredIgnoredColumnsAreAddedToTheIgnoreAttributes(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'ignored_columns' => ['id', 'ignoredField']], AuditedEntityWithAttribute::class);

        $ignoredColumns = $options['ignored_columns'];
        \assert(\is_array($ignoredColumns));
        sort($ignoredColumns);
        $this->assertSame(['id', 'ignoredField', 'ignoredPrivateField', 'ignoredProtectedField'], $ignoredColumns);
    }

    public function testConfiguredAuditedColumnsAreKeptWithIgnoreAttributes(): void
    {
        $options = $this->loadAttributeEntityWith(['enabled' => true, 'audited_columns' => ['auditedField', 'ignoredField']], AuditedEntityWithAttribute::class);

        $this->assertSame(['auditedField', 'ignoredField'], $options['audited_columns']);
        $this->assertContains('ignoredField', $options['ignored_columns'], 'The #[Ignore] attributes are kept.');
    }

    public function testIsAuditedFieldWithAuditedColumnsAndIgnoreAttributes(): void
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/Fixtures/Entity/Attribute',
            ],
            'default'
        );
        $auditor = $this->createAuditor();
        $provider = new DoctrineProvider($this->createProviderConfiguration([
            'entities' => [
                AuditedEntityWithAttribute::class => ['enabled' => true, 'audited_columns' => ['auditedField', 'ignoredField']],
            ],
        ]));
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($provider);

        $this->assertTrue($provider->isAuditedField(AuditedEntityWithAttribute::class, 'auditedField'), 'An allowlisted field is audited.');
        $this->assertFalse($provider->isAuditedField(AuditedEntityWithAttribute::class, 'ignoredField'), 'An allowlisted field marked with #[Ignore] is not audited.');
        $this->assertFalse($provider->isAuditedField(AuditedEntityWithAttribute::class, 'id'), 'A field missing from the allowlist is not audited.');
    }

    #[Depends('testIsAuditedHonorsEnabledFlag')]
    public function testEnableAuditFor(): void
    {
        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertFalse($provider->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');

        $configuration->enableAuditFor(Post::class);

        $this->assertTrue($provider->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');
    }

    #[Depends('testIsAuditedHonorsEnabledFlag')]
    public function testDisableAuditFor(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->createProviderConfiguration([
            'entities' => $entities,
        ]);
        $provider = $this->createDoctrineProvider($configuration);

        $this->assertTrue($provider->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $configuration->disableAuditFor(Post::class);

        $this->assertFalse($provider->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    public function testSetEntities(): void
    {
        $configuration = $this->createProviderConfiguration([
            'entities' => [Tag::class => null],
        ]);
        $entities1 = $configuration->getEntities();

        $entities = [
            Post::class => null,
            Comment::class => null,
        ];

        $configuration->setEntities($entities);
        $entities2 = $configuration->getEntities();

        $this->assertNotSame($entities2, $entities1, 'Configuration::setEntities() replaces previously configured entities.');
    }

    public function testGetConfiguration(): void
    {
        $provider = $this->createDoctrineProvider();

        $this->assertInstanceOf(Configuration::class, $provider->getConfiguration(), 'Configuration is reachable.');
    }

    public function testSetUserProvider(): void
    {
        $provider = $this->createDoctrineProvider();

        $before = $provider->getAuditor()->getConfiguration()->getUserProvider();
        $provider->getAuditor()->getConfiguration()->setUserProvider(new FakeUserProvider());
        $after = $provider->getAuditor()->getConfiguration()->getUserProvider();

        $this->assertIsCallable($after, 'UserProvider is a callable.');
        $this->assertNotSame($before, $after, 'UserProvider has changed.');

        $this->assertInstanceOf(User::class, $after(), 'UserProvider returns a User instance.');
    }

    public function testSetSecurityProvider(): void
    {
        $provider = $this->createDoctrineProvider();

        $before = $provider->getAuditor()->getConfiguration()->getSecurityProvider();
        $provider->getAuditor()->getConfiguration()->setSecurityProvider(new FakeSecurityProvider());
        $after = $provider->getAuditor()->getConfiguration()->getSecurityProvider();

        $this->assertIsCallable($after, 'SecurityProvider is a callable.');
        $this->assertNotSame($before, $after, 'SecurityProvider has changed.');

        $this->assertIsArray($after(), 'SecurityProvider returns an array.');
    }

    public function testSetRoleChecker(): void
    {
        $provider = $this->createDoctrineProvider();

        $before = $provider->getAuditor()->getConfiguration()->getRoleChecker();
        $provider->getAuditor()->getConfiguration()->setRoleChecker(new FakeRoleChecker());
        $after = $provider->getAuditor()->getConfiguration()->getRoleChecker();

        $this->assertIsCallable($after, 'RoleChecker is a callable.');
        $this->assertNotSame($before, $after, 'RoleChecker has changed.');

        $this->assertIsBool($after('', ''), 'RoleChecker returns a bool.');
    }

    public function testResetClearsPreparedStatements(): void
    {
        $provider = $this->createDoctrineProvider();

        // Access private preparedStatements via reflection to seed the cache
        $reflection = new \ReflectionProperty($provider, 'preparedStatements');
        $reflection->setValue($provider, ['some_key' => 'some_statement']);

        $this->assertNotEmpty($reflection->getValue($provider));

        $provider->reset();

        $this->assertEmpty($reflection->getValue($provider), 'reset() clears preparedStatements cache.');
    }

    public function testResetIsCalledOnSubscribers(): void
    {
        $provider = $this->createDoctrineProvider();

        // Seed the transaction cache of the subscriber attached to the entity manager via reflection
        $subscriber = $provider->getDoctrineSubscriber();
        $auditingService = $provider->getAuditingServices()['default'];
        $this->assertInstanceOf(AuditingService::class, $auditingService);
        $this->assertContains($subscriber, $auditingService->getEntityManager()->getEventManager()->getListeners(Events::onFlush));
        $transactionsReflection = new \ReflectionProperty($subscriber, 'transactions');
        $transactionsReflection->setValue($subscriber, [42 => 'stale_transaction']);

        $this->assertNotEmpty($transactionsReflection->getValue($subscriber));

        $provider->reset();

        $this->assertEmpty($transactionsReflection->getValue($subscriber), 'reset() clears subscriber transaction caches.');
    }

    public function testRegisterAuditingServiceAttachesTheSharedSubscriber(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();
        $eventManager = new EventManager();
        $provider->registerAuditingService(new AuditingService('EM1', $this->createEntityManagerWith($eventManager)));
        $provider->registerAuditingService(new AuditingService('EM2', $this->createEntityManagerWith($eventManager)));

        // entity managers sharing an event manager (as on one DoctrineBundle connection) get the subscriber once
        $this->assertSame([$provider->getDoctrineSubscriber()], array_values($eventManager->getListeners(Events::onFlush)));
        $this->assertSame([$provider->getDoctrineSubscriber()], array_values($eventManager->getListeners(Events::postFlush)));
    }

    public function testDisableListenerAttachment(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();
        $provider->disableListenerAttachment();

        $eventManager = new EventManager();
        $provider->registerAuditingService(new AuditingService('EM1', $this->createEntityManagerWith($eventManager)));

        $this->assertSame([], $eventManager->getAllListeners(), 'no listener is attached at runtime.');
        $this->assertArrayHasKey('EM1', $provider->getAuditingServices());
        $this->assertSame($provider->getDoctrineSubscriber(), $provider->getDoctrineSubscriber());
    }

    public function testDisableListenerAttachmentAfterRegisteringAnAuditingService(): void
    {
        $provider = $this->createUnregisteredDoctrineProvider();
        $provider->registerAuditingService(new AuditingService('EM1', $this->createEntityManager()));

        $this->expectException(ProviderException::class);
        $provider->disableListenerAttachment();
    }

    public function testImplementsResetInterface(): void
    {
        $provider = $this->createDoctrineProvider();

        $this->assertInstanceOf(ResetInterface::class, $provider);
    }

    private function createEntityManagerWith(EventManager $eventManager): EntityManager
    {
        $entityManager = $this->createEntityManager();

        return new EntityManager($entityManager->getConnection(), $entityManager->getConfiguration(), $eventManager);
    }

    /**
     * @param array<string, mixed> $configuredOptions
     * @param class-string         $entity
     *
     * @return array<string, mixed>
     */
    private function loadAttributeEntityWith(array $configuredOptions, string $entity = RetentionEntity::class): array
    {
        $entityManager = $this->createEntityManager(
            [
                __DIR__.'/../../../src/Provider/Doctrine/Auditing/Attribute',
                __DIR__.'/Fixtures/Entity/Attribute',
            ],
            'default'
        );

        $auditor = $this->createAuditor();
        $provider = new DoctrineProvider($this->createProviderConfiguration());
        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($provider);

        $provider->loadAttributes($entityManager, [$entity => $configuredOptions]);

        $configuration = $provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);
        $options = $configuration->getEntities()[$entity];
        \assert(\is_array($options));

        /** @var array<string, mixed> $options */
        return $options;
    }
}

final class FakeStorageMapper
{
    public function __invoke(string $entity, array $storageServices): StorageServiceInterface
    {
        return 0 === mb_strpos($entity, 'Foo') ? $storageServices['EM1'] : $storageServices['EM2'];
    }
}

final class FakeUserProvider implements UserProviderInterface
{
    public function __invoke(): UserInterface
    {
        return new User();
    }
}

final class FakeSecurityProvider implements SecurityProviderInterface
{
    public function __invoke(): array
    {
        return [];
    }
}

final class FakeRoleChecker implements RoleCheckerInterface
{
    public function __invoke(string $entity, string $scope): bool
    {
        return true;
    }
}
