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
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PhpBench\Attributes as Bench;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Benchmarks the queries of the audit viewer's user filter on a large audit table.
 *
 * The audit table is created by the SchemaManager of the checked out code (so it has the indices
 * of that code), then seeded once per subject with BENCH_ROWS rows written by BENCH_USERS users.
 *
 * Needs a real database server: an in-memory SQLite database has no query planner worth measuring.
 *
 *   BENCH_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3306/auditor" vendor/bin/phpbench run benchmarks/BlameFilterBench.php --report=aggregate --tag=before --store
 *   BENCH_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3306/auditor" vendor/bin/phpbench run benchmarks/BlameFilterBench.php --report=aggregate --ref=before
 */
#[Bench\Iterations(10)]
#[Bench\Revs(1)]
#[Bench\Warmup(1)]
#[Bench\BeforeClassMethods(['seed'])]
#[Bench\BeforeMethods(['connect'])]
#[Bench\AfterMethods(['disconnect'])]
class BlameFilterBench
{
    private const string AUDIT_TABLE = 'author_audit';

    private Connection $connection;

    /**
     * Distinct users of the audit table, as auditor-bundle 7.x fills the user filter of the viewer.
     */
    public function benchUserFilterOptions(): void
    {
        $this->connection->executeQuery(\sprintf(
            'SELECT DISTINCT blame_id, blame_user FROM %s WHERE blame_id IS NOT NULL ORDER BY blame_user',
            self::AUDIT_TABLE,
        ))->fetchAllAssociative();
    }

    /**
     * Audit entries of one user, first page (the viewer's user filter applied).
     */
    public function benchUserEntries(): void
    {
        $this->connection->executeQuery(\sprintf(
            'SELECT * FROM %s WHERE blame_id = ? ORDER BY created_at DESC, id DESC LIMIT 50',
            self::AUDIT_TABLE,
        ), ['7'])->fetchAllAssociative();
    }

    /**
     * Write cost of the extra index: 1,000 audit rows inserted in one transaction.
     */
    public function benchInsert(): void
    {
        $this->connection->transactional(static function (Connection $connection): void {
            self::insertRows($connection, 1_000, 1_000_000);
        });
    }

    public static function seed(): void
    {
        $connection = self::createConnection();
        $schemaManager = $connection->createSchemaManager();
        foreach ([self::AUDIT_TABLE, 'author'] as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $connection->executeStatement('DROP TABLE '.$table);
            }
        }

        // the audit table gets the columns and indices of the checked out SchemaManager
        $provider = self::createProvider($connection);
        $entityManager = $provider->getStorageServiceForEntity(Author::class)->getEntityManager();
        new SchemaTool($entityManager)->createSchema([$entityManager->getClassMetadata(Author::class)]);
        new SchemaManager($provider)->updateAuditSchema();

        $rows = self::seededRows();
        $connection->transactional(static function (Connection $connection) use ($rows): void {
            self::insertRows($connection, $rows, 0);
        });
        $connection->executeStatement('ANALYZE TABLE '.self::AUDIT_TABLE);
        $connection->close();
    }

    public function connect(): void
    {
        $this->connection = self::createConnection();
    }

    public function disconnect(): void
    {
        // drop the rows of benchInsert()
        $this->connection->executeStatement('DELETE FROM '.self::AUDIT_TABLE.' WHERE id > ?', [self::seededRows()]);
        $this->connection->close();
    }

    /**
     * Inserts audit rows by batches of 1,000: BENCH_USERS users (one row in ten is anonymous),
     * a few IPs per user and a realistic diff payload.
     */
    private static function insertRows(Connection $connection, int $count, int $firstObjectId): void
    {
        $users = max(1, (int) (getenv('BENCH_USERS') ?: 50));
        $diffs = json_encode([
            'fullname' => ['old' => 'John Doe', 'new' => 'Jane Doe'],
            'email' => ['old' => 'john.doe@example.com', 'new' => 'jane.doe@example.com'],
            'bio' => ['old' => str_repeat('Lorem ipsum dolor sit amet. ', 4), 'new' => str_repeat('Consectetur adipiscing elit. ', 4)],
        ], JSON_THROW_ON_ERROR);

        $columns = '(type, object_id, discriminator, transaction_hash, diffs, extra_data, blame_id, blame_user, blame_user_fqdn, blame_user_firewall, ip, created_at)';
        for ($offset = 0; $offset < $count; $offset += 1_000) {
            $values = [];
            $params = [];
            for ($i = $offset; $i < min($count, $offset + 1_000); ++$i) {
                $user = 0 === $i % 10 ? null : ($i + intdiv($i, 10)) % $users;
                $values[] = '(?, ?, NULL, ?, ?, NULL, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    ['insert', 'update', 'remove'][$i % 3],
                    (string) ($firstObjectId + intdiv($i, 5)),
                    sha1((string) intdiv($firstObjectId + $i, 3)),
                    $diffs,
                    null === $user ? null : (string) $user,
                    null === $user ? null : 'user'.$user,
                    null === $user ? null : 'App\Entity\User',
                    null === $user ? null : 'main',
                    null === $user ? null : '10.0.'.($user % 255).'.'.($i % 4),
                    date('Y-m-d H:i:s', 1_700_000_000 + $firstObjectId + $i),
                );
            }

            $connection->executeStatement('INSERT INTO '.self::AUDIT_TABLE.' '.$columns.' VALUES '.implode(', ', $values), $params);
        }
    }

    private static function seededRows(): int
    {
        return max(1, (int) (getenv('BENCH_ROWS') ?: 500_000));
    }

    private static function createConnection(): Connection
    {
        $databaseUrl = getenv('BENCH_DATABASE_URL');
        if (!\is_string($databaseUrl) || '' === $databaseUrl) {
            throw new \RuntimeException('BlameFilterBench needs BENCH_DATABASE_URL (e.g. "pdo-mysql://root:root@127.0.0.1:3306/auditor").');
        }

        $dbalConfig = new DBALConfiguration();
        $dbalConfig->setMiddlewares([new AuditorMiddleware()]);

        return DriverManager::getConnection(new DsnParser()->parse($databaseUrl), $dbalConfig);
    }

    private static function createProvider(Connection $connection): DoctrineProvider
    {
        $auditor = new Auditor(new AuditorConfiguration(['timezone' => 'UTC', 'enabled' => true]), new EventDispatcher());
        $provider = new DoctrineProvider(new Configuration([
            'table_prefix' => '',
            'table_suffix' => '_audit',
            'ignored_columns' => [],
            'entities' => [Author::class => ['enabled' => true]],
        ]));
        $auditor->registerProvider($provider);

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration([
            __DIR__.'/../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../tests/Provider/Doctrine/Fixtures/Entity/Standard/Blog',
        ], isDevMode: true);
        $ormConfig->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfig->enableNativeLazyObjects(true);
        $entityManager = new EntityManager($connection, $ormConfig);

        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        return $provider;
    }
}
