<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Auditing;

use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class JsonTest extends TestCase
{
    use ReaderTrait;
    use SchemaSetupTrait;

    public function testJsonInsert(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy
            ->setLabel('label')
            ->setJsonArray([
                'field1' => 'value1',
                'field2' => 'value2',
                'field3' => [
                    'field3.1' => 'value3.1',
                    'field3.2' => 'value3.2',
                ],
            ])
        ;

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(1, $audits, 'results count ok.');
        $entry = array_shift($audits);
        $this->assertSame([
            'json_array' => [
                'field1' => [
                    'new' => 'value1',
                ],
                'field2' => [
                    'new' => 'value2',
                ],
                'field3' => [
                    'field3.1' => [
                        'new' => 'value3.1',
                    ],
                    'field3.2' => [
                        'new' => 'value3.2',
                    ],
                ],
            ],
            'label' => [
                'new' => 'label',
                'old' => null,
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
        $this->assertSame(['json_array'], $this->fetchRawDiffs($entry)['deep_diffs'] ?? null);
    }

    public function testJsonUpdateExistingField(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy
            ->setLabel('label')
            ->setJsonArray([
                'field1' => 'value1',
                'field2' => 'value2',
                'field3' => [
                    'field3.1' => 'value3.1',
                    'field3.2' => 'value3.2',
                ],
            ])
        ;

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $dummy->setJsonArray([
            'field1' => 'new value1',
            'field2' => 'value2',
            'field3' => [
                'field3.1' => 'new value3.1',
                'field3.2' => 'value3.2',
            ],
        ]);

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(2, $audits, 'results count ok.');
        $entry = array_shift($audits);
        $this->assertSame([
            'json_array' => [
                'field1' => [
                    'new' => 'new value1',
                    'old' => 'value1',
                ],
                'field3' => [
                    'field3.1' => [
                        'new' => 'new value3.1',
                        'old' => 'value3.1',
                    ],
                ],
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testJsonAddNewField(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy
            ->setLabel('label')
            ->setJsonArray([
                'field1' => 'value1',
                'field2' => 'value2',
                'field3' => [
                    'field3.1' => 'value3.1',
                    'field3.2' => 'value3.2',
                ],
            ])
        ;

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $dummy->setJsonArray([
            'field1' => 'value1',
            'field2' => 'value2',
            'field3' => [
                'field3.1' => 'value3.1',
                'field3.2' => 'value3.2',
                'field3.3' => 'value3.3',
                'field3.4' => 'value3.4',
            ],
            'field4' => 'value4',
            'field5' => 'value5',
        ]);

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(2, $audits, 'results count ok.');
        $entry = array_shift($audits);
        $this->assertSame([
            'json_array' => [
                'field3' => [
                    'field3.3' => [
                        'new' => 'value3.3',
                    ],
                    'field3.4' => [
                        'new' => 'value3.4',
                    ],
                ],
                'field4' => [
                    'new' => 'value4',
                ],
                'field5' => [
                    'new' => 'value5',
                ],
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testJsonRemoveField(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy
            ->setLabel('label')
            ->setJsonArray([
                'field1' => 'value1',
                'field2' => 'value2',
                'field3' => [
                    'field3.1' => 'value3.1',
                    'field3.2' => 'value3.2',
                ],
                'field4' => [
                    'field4.1' => 'value4.1',
                    'field4.2' => 'value4.2',
                ],
            ])
        ;

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $dummy->setJsonArray([
            'field1' => 'value1',
            'field3' => [
                'field3.2' => 'value3.2',
            ],
        ]);

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(2, $audits, 'results count ok.');
        $entry = array_shift($audits);
        $this->assertSame([
            'json_array' => [
                'field2' => [
                    'old' => 'value2',
                ],
                'field3' => [
                    'field3.1' => [
                        'old' => 'value3.1',
                    ],
                ],
                'field4' => [
                    'field4.1' => [
                        'old' => 'value4.1',
                    ],
                    'field4.2' => [
                        'old' => 'value4.2',
                    ],
                ],
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    /**
     * A JSON value whose root only has "new"/"old" keys yields a deep diff with the shape of a
     * field diff: {"new": 1} updated to {"new": 2} is stored as {"new": {"new": 2, "old": 1}}.
     * "deep_diffs" tells readers that it is a deep diff.
     */
    public function testJsonUpdateWithNewKeyAtRootIsListedAsDeepDiff(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy->setLabel('label')->setJsonArray(['new' => 1]);

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $dummy->setJsonArray(['new' => 2]);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(2, $audits, 'results count ok.');
        [$update, $insert] = $audits;

        $this->assertSame(['json_array' => ['new' => ['new' => 2, 'old' => 1]]], $update->getDiffs());
        $this->assertSame(['json_array'], $this->fetchRawDiffs($update)['deep_diffs'] ?? null);

        $this->assertSame([
            'json_array' => ['new' => ['new' => 1]],
            'label' => ['new' => 'label', 'old' => null],
        ], $insert->getDiffs());
        $this->assertSame(['json_array'], $this->fetchRawDiffs($insert)['deep_diffs'] ?? null);
    }

    /**
     * A JSON field whose value goes from a scalar to an array gets a field diff, not a deep diff.
     */
    public function testJsonFieldDiffIsNotListedAsDeepDiff(): void
    {
        $storageServices = [
            DummyEntity::class => $this->provider->getStorageServiceForEntity(DummyEntity::class),
        ];

        $reader = $this->createReader();

        $dummy = new DummyEntity();
        $dummy->setLabel('label')->setJsonArray('scalar');

        $storageServices[DummyEntity::class]->getEntityManager()->persist($dummy);
        $this->flushAll($storageServices);

        $dummy->setJsonArray(['new' => 1]);
        $this->flushAll($storageServices);

        $audits = $reader->createQuery(DummyEntity::class)->execute();
        $this->assertCount(2, $audits, 'results count ok.');
        [$update, $insert] = $audits;

        $this->assertSame(['json_array' => ['new' => ['new' => 1], 'old' => 'scalar']], $update->getDiffs());
        $this->assertArrayNotHasKey('deep_diffs', $this->fetchRawDiffs($update));
        $this->assertArrayNotHasKey('deep_diffs', $this->fetchRawDiffs($insert));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRawDiffs(Entry $entry): array
    {
        $connection = $this->provider->getStorageServiceForEntity(DummyEntity::class)->getEntityManager()->getConnection();
        $diffs = $connection->fetchOne('SELECT diffs FROM dummy_entity_audit WHERE id = ?', [$entry->id]);
        $this->assertIsString($diffs);

        $decoded = json_decode($diffs, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            DummyEntity::class => ['enabled' => true],
        ]);
    }
}
