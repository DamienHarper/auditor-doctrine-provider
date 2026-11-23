<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing\Root;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Command\RebuildRootsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Bike;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Car;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Kitten;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Owner;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Pet;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Region;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Toy;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root\Zone;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Roots whose identifier is not a plain "id", roots and satellites in inheritance hierarchies, and the rebuild of
 * their index.
 *
 * @internal
 */
#[Small]
final class RootIdentityTest extends TestCase
{
    use SchemaSetupTrait;

    public function testRootIdsAreWrittenLikeObjectIds(): void
    {
        $em = $this->entityManager();
        $owner = new Owner('ACME', 'Acme');
        $zone = new Zone(Region::North);
        $car = new Car('car', $owner, $zone);
        foreach ([$owner, $zone, $car] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $this->assertSame([
            [Owner::class, 'ACME', 'owner', (string) $car->id],
            [Zone::class, 'north', 'zone', (string) $car->id],
        ], $this->rows(), 'A primary key not named "id" and a backed enum primary key are written as the audit tables write them.');

        $history = new Reader($this->provider)->createRootQuery(Owner::class, 'ACME');
        $this->assertSame(1, $history->count());
    }

    public function testOnlyTheEntitiesThatDeclareRootsAreIndexedInASharedTable(): void
    {
        $em = $this->entityManager();
        $owner = new Owner('ACME', 'Acme');
        $car = new Car('car', $owner);
        $bike = new Bike('bike', $owner);
        foreach ([$owner, $car, $bike] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $this->assertSame([[Owner::class, 'ACME', 'owner', (string) $car->id]], $this->rows(), 'Bike declares no root.');
    }

    public function testTheRebuiltIndexIsTheOneWrittenAlongWithTheEntries(): void
    {
        $em = $this->entityManager();
        $acme = new Owner('ACME', 'Acme');
        $globex = new Owner('GLOBEX', 'Globex');
        $zone = new Zone(Region::South);
        $car = new Car('car', $acme, $zone);
        $bike = new Bike('bike', $acme, $zone);
        foreach ([$acme, $globex, $zone, $car, $bike] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        $car->owner = $globex;
        $em->flush();

        $written = $this->rows();
        $this->assertContains([Owner::class, 'GLOBEX', 'owner', (string) $car->id], $written);
        $this->assertNotContains('bike', array_column($written, 3));

        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM audit_root');

        $tester = new CommandTester(new RebuildRootsCommand()->setAuditor($this->provider->getAuditor()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame($written, $this->rows());
    }

    public function testEntriesOfARootInAJoinedHierarchy(): void
    {
        $em = $this->entityManager();
        $kitten = new Kitten('Tom');
        $toy = new Toy('ball', $kitten);
        $em->persist($kitten);
        $em->persist($toy);
        $em->flush();

        \assert(null !== $kitten->id);
        $entries = iterator_to_array(new Reader($this->provider)->createRootQuery(Pet::class, $kitten->id)->iterate(), false);

        $entities = array_values(array_unique(array_map(static fn (Entry $entry): ?string => $entry->entity, $entries)));
        sort($entities);

        $this->assertSame([Kitten::class, Toy::class], $entities, 'The entries of the root itself carry the class of their audit table.');
    }

    public function testInvalidRootQueries(): void
    {
        $reader = new Reader($this->provider);

        try {
            $reader->createRootQuery('App\Unknown', 1);
            $this->fail('An unknown root class is refused.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('App\Unknown', $invalidArgumentException->getMessage());
        }

        try {
            $reader->createRootQuery(Owner::class, 'ACME', ['include' => ['App\Unknown']]);
            $this->fail('An unknown entity in "include" is refused.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('include', $invalidArgumentException->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('paths');

        $reader->createRootQuery(Owner::class, 'ACME', ['paths' => ['owner.unknown']]);
    }

    public function testEntityLabelResolver(): void
    {
        $this->provider->getConfiguration()->setEntityLabelResolver(static fn (object $entity): ?\Stringable => $entity instanceof Owner ? new readonly class($entity->code) implements \Stringable {
            public function __construct(private string $code) {}

            public function __toString(): string
            {
                return 'Owner '.$this->code;
            }
        } : null);

        $em = $this->entityManager();
        $owner = new Owner('ACME', 'Acme');
        $car = new Car('car', $owner);
        $em->persist($owner);
        $em->persist($car);
        $em->flush();

        $entry = new Reader($this->provider)->createQuery(Car::class)->execute()[0];

        $this->assertSame('Owner ACME', $entry->getDiffs()['owner']['new']['label'] ?? null, 'The label comes from the resolver, a Stringable is accepted.');
        $this->assertSame(Car::class.'#'.$car->id, $entry->getDiffSource()['label'] ?? null, 'Null keeps the default label.');
    }

    /**
     * @return list<array{string, string, string, string}>
     */
    private function rows(): array
    {
        $rows = $this->entityManager()->getConnection()->fetchAllNumeric('SELECT root_class, root_id, root_path, object_id FROM audit_root ORDER BY root_class, root_id, root_path, object_id');

        return array_map(static fn (array $row): array => array_map(strval(...), $row), $rows);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->provider->getAuditingServiceForEntity(Car::class)->getEntityManager();
    }

    private function createAndInitDoctrineProvider(): void
    {
        $auditor = $this->createAuditor();
        $this->provider = new DoctrineProvider($this->createProviderConfiguration());

        $entityManager = $this->createEntityManager([
            __DIR__.'/../../../../../src/Provider/Doctrine/Auditing/Attribute',
            __DIR__.'/../../Fixtures/Root',
        ]);
        $this->provider->registerStorageService(new StorageService('default', $entityManager));
        $this->provider->registerAuditingService(new AuditingService('default', $entityManager));

        $auditor->registerProvider($this->provider);
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Car::class => ['enabled' => true, 'roots' => ['owner', 'zone']],
            Bike::class => ['enabled' => true],
            Kitten::class => ['enabled' => true],
            Toy::class => ['enabled' => true, 'roots' => ['pet']],
        ]);
    }
}
