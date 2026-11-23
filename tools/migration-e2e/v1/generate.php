<?php

declare(strict_types=1);

/*
 * Writes audit entries with auditor-doctrine-provider 1.x (the version installed in this directory), then
 * saves every audit row as it is in the database. The 2.x test (tests/MigrationE2E) migrates the same
 * databases and compares the result with this snapshot.
 *
 * Usage: php tools/migration-e2e/v1/generate.php (settings: see tests/MigrationE2E/Fixtures/Environment.php)
 */

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\Service\StorageServiceInterface;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Blame;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Environment;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Scenario;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Type\MoneyType;
use DH\Auditor\User\User;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\EventDispatcher\EventDispatcher;

MoneyType::register();

/**
 * Connects to a storage and drops every table left by a previous run.
 */
function connect(string $storage): Connection
{
    $params = Environment::connectionParameters($storage);
    if ('pdo_sqlite' === $params['driver'] && is_file($params['path'])) {
        unlink($params['path']);
    }

    $configuration = new DbalConfiguration();
    $configuration->setMiddlewares([new AuditorMiddleware()]);
    $connection = DriverManager::getConnection($params, $configuration);

    foreach ($connection->createSchemaManager()->introspectSchema()->toDropSql($connection->getDatabasePlatform()) as $sql) {
        $connection->executeStatement($sql);
    }

    return $connection;
}

function entityManager(Connection $connection): EntityManagerInterface
{
    $configuration = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
        ? ORMSetup::createAttributeMetadataConfig([Environment::entityPath()], true)
        : ORMSetup::createAttributeMetadataConfiguration([Environment::entityPath()], true);
    $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $configuration->enableNativeLazyObjects(true);

    return new EntityManager($connection, $configuration);
}

$entityManagers = [
    Environment::PRIMARY => entityManager(connect(Environment::PRIMARY)),
    Environment::SECONDARY => entityManager(connect(Environment::SECONDARY)),
];

$auditor = new Auditor(new AuditorConfiguration(['timezone' => 'UTC', 'enabled' => true]), new EventDispatcher());
$auditor->getConfiguration()
    ->setUserProvider(static fn (): ?User => null === Blame::$user ? null : new User(Blame::$user[0], Blame::$user[1]))
    ->setSecurityProvider(static fn (): array => [Blame::$ip, Blame::$firewall])
    ->setExtraDataProvider(static fn (): ?array => Blame::$extraData)
;

$provider = new DoctrineProvider(new Configuration([
    'table_prefix' => '',
    'table_suffix' => '_audit',
    'ignored_columns' => [],
    'entities' => Environment::entities(),
    'viewer' => false,
]));
$provider->registerStorageService(new StorageService(Environment::PRIMARY, $entityManagers[Environment::PRIMARY]));
$provider->registerStorageService(new StorageService(Environment::SECONDARY, $entityManagers[Environment::SECONDARY]));
$provider->registerAuditingService(new AuditingService('default', $entityManagers[Environment::PRIMARY]));
$auditor->registerProvider($provider);
$provider->getConfiguration()->setStorageMapper(
    static fn (string $entity, array $storageServices): StorageServiceInterface => $storageServices[
        \in_array($entity, Environment::secondaryEntities(), true) ? Environment::SECONDARY : Environment::PRIMARY
    ]
);

$primary = $entityManagers[Environment::PRIMARY];
new SchemaTool($primary)->createSchema($primary->getMetadataFactory()->getAllMetadata());
new SchemaManager($provider)->updateAuditSchema();

new Scenario($primary)->run();

// Every audit row, as stored by 1.x
$snapshot = [
    'provider_version' => InstalledVersions::getPrettyVersion('damienharper/auditor-doctrine-provider').' ('.InstalledVersions::getReference('damienharper/auditor-doctrine-provider').')',
    'storages' => [],
];
foreach ($entityManagers as $storage => $entityManager) {
    $connection = $entityManager->getConnection();
    foreach ($connection->createSchemaManager()->introspectTableNames() as $name) {
        $table = $name->getUnqualifiedName()->getValue();
        if (!str_ends_with($table, '_audit')) {
            continue;
        }

        $snapshot['storages'][$storage][$table] = $connection->fetchAllAssociative(\sprintf('SELECT * FROM %s ORDER BY id', $table));
    }
}

file_put_contents(Environment::snapshotPath(), json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

$counts = [];
foreach ($snapshot['storages'] as $storage => $tables) {
    foreach ($tables as $table => $rows) {
        $counts[] = \sprintf('%s.%s: %d', $storage, $table, \count($rows));
    }
}

echo $snapshot['provider_version'], "\n", implode("\n", $counts), "\n";
