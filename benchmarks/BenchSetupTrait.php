<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Benchmarks;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\User\User;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Provider, entity manager and schema of the benchmarks: in-memory SQLite, or the server of BENCH_DATABASE_URL.
 */
trait BenchSetupTrait
{
    private EntityManagerInterface $em;

    private DoctrineProvider $provider;

    private function buildProvider(): void
    {
        $auditorConfig = new AuditorConfiguration(['timezone' => 'UTC', 'enabled' => true]);
        $auditor       = new Auditor($auditorConfig, new EventDispatcher());

        $providerConfig = new Configuration([
            'table_prefix'    => '',
            'table_suffix'    => '_audit',
            'ignored_columns' => [],
            'entities'        => [],
            // BENCH_BATCH_SIZE=500 measures the multi-row INSERT of audit entries
            'batch_size'      => max(0, (int) (getenv('BENCH_BATCH_SIZE') ?: 0)),
        ]);
        $this->provider = new DoctrineProvider($providerConfig);
        $auditor->registerProvider($this->provider);

        $this->em = $this->buildEntityManager();

        $this->provider->registerStorageService(new StorageService('default', $this->em));
        $this->provider->registerAuditingService(new AuditingService('default', $this->em));

        $this->provider->getAuditor()->getConfiguration()->setUserProvider(
            static fn (): User => new User('1', 'bench.user')
        );
        $this->provider->getAuditor()->getConfiguration()->setSecurityProvider(
            static fn (): array => ['127.0.0.1', 'bench']
        );

        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class   => ['enabled' => true],
            Tag::class    => ['enabled' => true],
        ]);
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $paths = [
            __DIR__.'/../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../tests/Provider/Doctrine/Fixtures/Entity/Standard/Blog',
        ];

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, isDevMode: true);
        $ormConfig->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfig->enableNativeLazyObjects(true);

        $dbalConfig = new DBALConfiguration();
        $dbalConfig->setMiddlewares([new AuditorMiddleware()]);

        // BENCH_DATABASE_URL targets a real server (e.g. "pdo-mysql://auditor:password@127.0.0.1:3361/auditor");
        // without it, the benchmark runs on an in-memory SQLite database.
        $databaseUrl = getenv('BENCH_DATABASE_URL');
        $params = \is_string($databaseUrl) && '' !== $databaseUrl
            ? new DsnParser()->parse($databaseUrl)
            : ['driver' => 'pdo_sqlite', 'memory' => true];

        $connection = DriverManager::getConnection($params, $dbalConfig);

        $em  = new EntityManager($connection, $ormConfig);
        $evm = $em->getEventManager();
        $evm->addEventListener(Events::onFlush, new SoftDeleteableListener());

        return $em;
    }

    private function buildSchema(): void
    {
        // A real server keeps the tables of the previous iteration: start from an empty database.
        $connection = $this->em->getConnection();
        foreach ($connection->createSchemaManager()->introspectSchema()->toDropSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        $schemaTool = new SchemaTool($this->em);
        $classes    = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($classes);

        $schemaManager = new SchemaManager($this->provider);
        $schemaManager->updateAuditSchema();
    }

    /**
     * Writes $rows audit rows of $entity straight into its audit table (multi-row INSERTs, not measured): 1 row in
     * 10 for each of 5,000 objects, 50 users, 10 rows per transaction, one second apart.
     */
    private function seedAuditRows(string $entity, int $rows): void
    {
        if ($rows <= 0) {
            return;
        }

        $connection = $this->em->getConnection();
        $table = $this->provider->getConfiguration()->getEntities()[$entity]['computed_audit_table_name'];
        $cast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';
        $placeholders = "(?, ?, ?, ?, ?{$cast}, ?, ?{$cast}, ?)";
        $types = ['insert', 'update', 'update', 'update', 'remove', 'associate'];
        $base = strtotime('2026-01-01 00:00:00');
        $batch = 90; // 8 parameters per row: below the 999 parameters of old SQLite versions

        for ($i = 0; $i < $rows; $i += $batch) {
            $values = [];
            $count = min($batch, $rows - $i);
            for ($j = 0; $j < $count; ++$j) {
                $n = $i + $j;
                $objectId = (string) (($n % 5000) + 1);
                $user = (string) (($n % 50) + 1);
                array_push(
                    $values,
                    2,
                    $types[$n % 6],
                    $objectId,
                    \sprintf('%026d', intdiv($n, 10)),
                    json_encode(['source' => ['id' => $objectId, 'class' => $entity, 'label' => '#'.$objectId, 'table' => 'x'], 'changes' => ['title' => ['old' => 'old title '.$n, 'new' => 'new title '.$n]]], JSON_THROW_ON_ERROR),
                    $user,
                    json_encode(['username' => 'user'.$user, 'user_fqdn' => User::class, 'user_firewall' => 'main', 'ip' => '127.0.0.1'], JSON_THROW_ON_ERROR),
                    date('Y-m-d H:i:s', $base + $n),
                );
            }

            $connection->executeStatement(
                \sprintf('INSERT INTO %s (schema_version, type, object_id, transaction_id, diffs, blame_id, blame, created_at) VALUES %s', $table, implode(', ', array_fill(0, $count, $placeholders))),
                $values,
            );
        }
    }
}
