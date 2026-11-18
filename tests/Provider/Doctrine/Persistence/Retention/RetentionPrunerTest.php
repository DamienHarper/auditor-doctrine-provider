<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Retention;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Retention\RetentionPruner;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Bike;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Inheritance\SingleTable\Vehicle;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class RetentionPrunerTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testNoPolicyAndNoDefaultCutoffDeletesNothing(): void
    {
        $this->insertEntries(Post::class, ['old' => 'P10Y', 'recent' => 'PT1M']);

        $statements = $this->createPruner()->pruneEntity(Post::class, null, null);

        $this->assertSame([], $statements);
        $this->assertSame(['old', 'recent'], $this->remainingEntries(Post::class));
    }

    public function testEmptyPolicyBehavesLikeNoPolicy(): void
    {
        $this->insertEntries(Post::class, ['old' => 'P10Y', 'recent' => 'PT1M']);

        $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy(), null);

        $this->assertSame(['old', 'recent'], $this->remainingEntries(Post::class));
    }

    public function testDefaultCutoffAppliesToEntitiesWithoutPolicy(): void
    {
        $this->insertEntries(Post::class, ['older' => 'P40D', 'old' => 'P31D', 'recent' => 'P29D']);

        $pruner = $this->createPruner();
        $pruner->pruneEntity(Post::class, null, $pruner->now()->sub(new \DateInterval('P30D')));

        $this->assertSame(['recent'], $this->remainingEntries(Post::class));
    }

    public function testMaxAgeOverridesALongerDefaultCutoff(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P20D', 'b' => 'P3D', 'c' => 'P1D']);

        $pruner = $this->createPruner();
        $pruner->pruneEntity(Post::class, new RetentionPolicy('P2D'), $pruner->now()->sub(new \DateInterval('P30D')));

        $this->assertSame(['c'], $this->remainingEntries(Post::class));
    }

    public function testMaxAgeOverridesAShorterDefaultCutoff(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P2Y', 'b' => 'P10D', 'c' => 'PT1M']);

        $pruner = $this->createPruner();
        $pruner->pruneEntity(Post::class, new RetentionPolicy('P1Y'), $pruner->now()->sub(new \DateInterval('P1D')));

        $this->assertSame(['b', 'c'], $this->remainingEntries(Post::class));
    }

    public function testMaxEntriesKeepsTheMostRecentEntries(): void
    {
        // "c" and "d" share the same creation date: the most recently inserted one ("d") wins the tie.
        $this->insertEntries(Post::class, ['a' => 'P5D', 'b' => 'P4D', 'c' => 'P3D', 'd' => 'P3D', 'e' => 'P1D']);

        $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy(maxEntries: 2), null);

        $this->assertSame(['d', 'e'], $this->remainingEntries(Post::class));
    }

    public function testMaxEntriesWithoutExcessEntriesDeletesNothing(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P5D', 'b' => 'P4D']);

        $statements = $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy(maxEntries: 2), null);

        $this->assertSame([], $statements);
        $this->assertSame(['a', 'b'], $this->remainingEntries(Post::class));
    }

    public function testMaxEntriesAppliesOnTopOfTheDefaultCutoff(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P2Y', 'b' => 'P5D', 'c' => 'P4D', 'd' => 'P3D']);

        $pruner = $this->createPruner();
        $pruner->pruneEntity(Post::class, new RetentionPolicy(maxEntries: 2), $pruner->now()->sub(new \DateInterval('P1Y')));

        $this->assertSame(['c', 'd'], $this->remainingEntries(Post::class));
    }

    public function testMaxAgeAndMaxEntriesAreCombined(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P20D', 'b' => 'P5D', 'c' => 'P4D', 'd' => 'P3D']);

        // "a" is too old, "b" is beyond the 2 most recent entries.
        $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy('P10D', 2), null);

        $this->assertSame(['c', 'd'], $this->remainingEntries(Post::class));
    }

    public function testPoliciesOnlyAffectTheirOwnEntity(): void
    {
        $this->insertEntries(Post::class, ['post-old' => 'P2Y', 'post-recent' => 'PT1M']);
        $this->insertEntries(Author::class, ['author-old' => 'P2Y', 'author-recent' => 'PT1M']);

        $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy('P1Y', 1), null);

        $this->assertSame(['post-recent'], $this->remainingEntries(Post::class));
        $this->assertSame(['author-old', 'author-recent'], $this->remainingEntries(Author::class));
    }

    public function testSingleTableInheritanceStatementsAreRestrictedToTheEntity(): void
    {
        // Car and Bike share the audit table of Vehicle.
        $this->assertSame($this->auditTable(Car::class), $this->auditTable(Bike::class));

        $this->insertEntries(Car::class, ['car-1' => 'P2Y', 'car-2' => 'P10D', 'car-3' => 'P1D']);
        $this->insertEntries(Bike::class, ['bike-1' => 'P2Y', 'bike-2' => 'P10D', 'bike-3' => 'P1D']);

        $pruner = $this->createPruner();

        // Car keeps its single most recent entry; Bike entries are untouched.
        $pruner->pruneEntity(Car::class, new RetentionPolicy(maxEntries: 1), null);
        $this->assertSame(['car-3', 'bike-1', 'bike-2', 'bike-3'], $this->remainingEntries(Car::class));

        // The default cutoff applied to Bike does not delete old Car entries (and vice versa).
        $this->insertEntries(Car::class, ['car-old' => 'P2Y']);
        $pruner->pruneEntity(Bike::class, null, $pruner->now()->sub(new \DateInterval('P1Y')));
        $this->assertSame(['car-3', 'bike-2', 'bike-3', 'car-old'], $this->remainingEntries(Car::class));

        // A long max_age on Car protects its entries from a shorter default cutoff.
        $pruner->pruneEntity(Car::class, new RetentionPolicy('P3Y'), $pruner->now()->sub(new \DateInterval('P1D')));
        $this->assertSame(['car-3', 'bike-2', 'bike-3', 'car-old'], $this->remainingEntries(Car::class));
    }

    public function testDryRunDeletesNothingButReturnsTheStatements(): void
    {
        $this->insertEntries(Post::class, ['a' => 'P2Y', 'b' => 'P5D', 'c' => 'P4D']);

        $statements = $this->createPruner()->pruneEntity(Post::class, new RetentionPolicy('P1Y', 1), null, true);

        $this->assertCount(2, $statements);
        $table = $this->auditTable(Post::class);
        $this->assertMatchesRegularExpression('/^DELETE FROM '.preg_quote($table, '/').' WHERE created_at < \'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\'$/', $statements[0]);
        $this->assertMatchesRegularExpression('/^DELETE FROM '.preg_quote($table, '/').' WHERE \(created_at < \'[^\']+\'\) OR \(\(created_at = \'[^\']+\'\) AND \(id <= \d+\)\)$/', $statements[1]);
        $this->assertSame(['a', 'b', 'c'], $this->remainingEntries(Post::class));
    }

    public function testGetPoliciesSkipsEntitiesWithoutPolicy(): void
    {
        $this->setEntityOptions([
            Post::class => ['max_age' => 'P1Y', 'max_entries' => 10],
            Author::class => ['max_age' => null, 'max_entries' => null],
            Car::class => [],
        ]);

        $policies = $this->createPruner()->getPolicies([Post::class, Author::class, Car::class]);

        $this->assertSame([Post::class], array_keys($policies));
        $this->assertSame('P1Y', $policies[Post::class]->maxAge);
        $this->assertSame(10, $policies[Post::class]->maxEntries);
    }

    public function testGetPoliciesReportsEveryInvalidPolicy(): void
    {
        $this->setEntityOptions([
            Post::class => ['max_age' => 'P0D'],
            Author::class => ['max_entries' => 0],
            Car::class => ['max_age' => 30],
            Bike::class => ['max_entries' => '10'],
        ]);

        try {
            $this->createPruner()->getPolicies([Post::class, Author::class, Car::class, Bike::class]);
            $this->fail('An exception should have been thrown.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $message = $invalidArgumentException->getMessage();
            $this->assertStringContainsString(\sprintf('Entity "%s": Invalid retention "maxAge" value "P0D"', Post::class), $message);
            $this->assertStringContainsString(\sprintf('Entity "%s": Invalid retention "maxEntries" value 0', Author::class), $message);
            $this->assertStringContainsString(\sprintf('Entity "%s": "max_age" must be a string, int given.', Car::class), $message);
            $this->assertStringContainsString(\sprintf('Entity "%s": "max_entries" must be an integer, string given.', Bike::class), $message);
        }
    }

    private function configureEntities(): void
    {
        $this->setEntityOptions([]);
    }

    /**
     * @param array<string, array<string, mixed>> $options
     */
    private function setEntityOptions(array $options): void
    {
        $entities = [];
        foreach ([Author::class, Post::class, Vehicle::class, Car::class, Bike::class] as $entity) {
            $entities[$entity] = ['enabled' => true] + ($options[$entity] ?? []);
        }

        $this->provider->getConfiguration()->setEntities($entities);
    }

    private function createPruner(): RetentionPruner
    {
        return new RetentionPruner($this->provider);
    }

    private function auditTable(string $entity): string
    {
        $configuration = $this->provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);
        $table = new SchemaManager($this->provider)->resolveAuditTableName($entity, $configuration);
        \assert(\is_string($table));

        return $table;
    }

    /**
     * Inserts audit entries, in the given order, created the given ISO 8601 duration ago.
     *
     * @param array<string, string> $entries object_id => age
     */
    private function insertEntries(string $entity, array $entries): void
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $jsonCast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';
        $discriminator = \in_array($entity, [Car::class, Bike::class], true) ? $entity : null;
        // Truncated to the second so that entries of the same age share the exact same creation date.
        $now = new \DateTimeImmutable($this->createPruner()->now()->format('Y-m-d H:i:s'), $this->createPruner()->getTimezone());

        foreach ($entries as $objectId => $age) {
            $connection->executeStatement(
                \sprintf('INSERT INTO %s (schema_version, type, object_id, discriminator, diffs, created_at) VALUES (?, ?, ?, ?, ?%s, ?)', $this->auditTable($entity), $jsonCast),
                [2, 'update', $objectId, $discriminator, '{}', $now->sub(new \DateInterval($age))->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * @return list<string> object ids of the remaining entries, in insertion order
     */
    private function remainingEntries(string $entity): array
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        /** @var list<string> */
        return array_map(strval(...), $connection->fetchFirstColumn(\sprintf('SELECT object_id FROM %s ORDER BY id', $this->auditTable($entity))));
    }
}
