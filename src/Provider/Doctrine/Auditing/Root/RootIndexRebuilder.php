<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Root;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Command\RebuildRootsCommandTest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Rebuilds the audit root index of existing audit entries, by replaying their diffs: the value of the first segment
 * of a path is read from the diffs of the entity itself, the next segments from the audit history of the entities
 * the path goes through, as they were when the entry was written.
 *
 * Only schema v2 entries are indexed. Only missing rows are written: rows written by hand, by a "root_resolver" (which
 * cannot be replayed) or for entries whose diffs do not hold the association are kept.
 *
 * @internal
 *
 * @see RebuildRootsCommandTest
 */
final class RootIndexRebuilder
{
    /**
     * Values of the associations of audited objects over time: class => field => object id => list of [position, value].
     *
     * @var array<string, array<string, array<string, list<array{string, ?array{string, string}}>>>>
     */
    private array $timelines = [];

    /**
     * Entries of the tables a path goes through whose diffs cannot be decoded.
     */
    private int $unreadableInTimelines = 0;

    public function __construct(private readonly DoctrineProvider $provider) {}

    /**
     * Audit tables whose entries have roots, with the paths of each entity written to them.
     *
     * @param null|list<string> $entities FQCNs to rebuild, all by default
     *
     * @return array<string, array{connection: Connection, entities: array<string, list<string>>}> keyed by audit table
     */
    public function tables(?array $entities = null): array
    {
        $configuration = $this->configuration();
        $tables = [];
        foreach (array_keys($configuration->getEntities()) as $entity) {
            $entity = (string) $entity;
            $paths = $configuration->getRoots($entity);
            if ([] === $paths || !$this->provider->hasAuditTable($entity)) {
                continue;
            }

            if (null !== $entities && !\in_array($entity, $entities, true)) {
                continue;
            }

            $table = $configuration->getEntities()[$entity]['computed_audit_table_name'];
            \assert(\is_string($table));
            $tables[$table] ??= [
                'connection' => $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection(),
                'entities' => [],
            ];
            $tables[$table]['entities'][$entity] = $paths;
        }

        return $tables;
    }

    /**
     * Adds the missing index rows of an audit table and returns the number of rows written (to write, with $dryRun).
     * Existing rows are never deleted.
     *
     * @param array{connection: Connection, entities: array<string, list<string>>} $table
     *
     * @return array{entries: int, rows: int, legacy: int, unreadable: int} entries read, missing index rows, entries
     *                                                                      skipped because they are schema v1 or have
     *                                                                      no transaction id, entries whose diffs
     *                                                                      cannot be decoded
     */
    public function rebuild(string $auditTable, array $table, bool $dryRun = false): array
    {
        $connection = $table['connection'];
        $indexTable = $this->configuration()->getRootIndexTableName();
        $single = 1 === \count($table['entities']);
        $allPaths = array_values(array_unique(array_merge(...array_values($table['entities']))));

        $rows = [];
        $entries = 0;
        $legacy = 0;
        $unreadable = 0;
        $unreadableBefore = $this->unreadableInTimelines;

        /** @var array<string, array<string, ?array{string, string}>> $state object id => field => value */
        $state = [];
        $result = $connection->executeQuery(\sprintf('SELECT id, type, object_id, discriminator, transaction_id, diffs, created_at, schema_version FROM %s ORDER BY created_at ASC, transaction_id ASC, id ASC', $auditTable));
        foreach ($result->iterateAssociative() as $row) {
            ++$entries;
            if (2 > $this->schemaVersion($row) || !\is_string($row['transaction_id'] ?? null) || '' === $row['transaction_id']) {
                ++$legacy;

                continue;
            }

            // an entity of an inheritance hierarchy is written with its discriminator: only its own paths apply
            $entity = \is_string($row['discriminator'] ?? null) && '' !== $row['discriminator']
                ? $row['discriminator']
                : ($single ? array_key_first($table['entities']) : null);
            $paths = null === $entity ? [] : ($table['entities'][$entity] ?? []);
            $objectId = (string) $row['object_id'];
            $createdAt = (string) $row['created_at'];
            $position = $createdAt.' '.$row['transaction_id'];
            $changes = $this->changes($row['diffs']);
            if (null === $changes) {
                ++$unreadable;

                continue;
            }

            $found = [];
            foreach ($paths as $path) {
                $segments = explode('.', $path);
                $first = array_shift($segments);

                // values of the first segment: before and after the entry when it changes it
                $values = [];
                $change = $changes[$first] ?? null;
                if (\is_array($change) && (\array_key_exists('old', $change) || \array_key_exists('new', $change))) {
                    $values[] = $this->reference($change['old'] ?? null);
                    $values[] = $this->reference($change['new'] ?? null);
                    if (\array_key_exists('new', $change)) {
                        $state[$objectId][$first] = $this->reference($change['new']);
                    }
                } else {
                    $values[] = $state[$objectId][$first] ?? null;
                }

                foreach ($values as $value) {
                    foreach ($segments as $segment) {
                        $value = null === $value ? null : $this->valueAt($value[0], $value[1], $segment, $position);
                    }

                    if (null !== $value) {
                        $key = $value[0]."\0".$value[1]."\0".$path;
                        $found[$key] = [$this->rootClass($value[0], $entity), $value[1], $path];
                    }
                }
            }

            foreach ($found as [$rootClass, $rootId, $path]) {
                $rows[] = [
                    'root_class' => $rootClass,
                    'root_id' => $rootId,
                    'root_path' => $path,
                    'audit_table' => $auditTable,
                    'object_id' => $objectId,
                    'transaction_id' => $row['transaction_id'],
                    'created_at' => $createdAt,
                ];
            }
        }

        // rows already in the index are kept, and never written twice: rows written by hand (or by a former
        // configuration) for entries that the diffs cannot replay survive a rebuild
        $existing = [];
        if ([] !== $allPaths) {
            $result = $connection->executeQuery(
                \sprintf('SELECT root_class, root_id, root_path, object_id, transaction_id FROM %s WHERE audit_table = ? AND root_path IN (?)', $indexTable),
                [$auditTable, $allPaths],
                [ParameterType::STRING, ArrayParameterType::STRING],
            );
            foreach ($result->iterateNumeric() as $existingRow) {
                $existing[implode("\0", array_map(strval(...), $existingRow))] = true;
            }
        }

        $rows = array_values(array_filter($rows, static function (array $row) use (&$existing): bool {
            $key = implode("\0", [$row['root_class'], $row['root_id'], $row['root_path'], $row['object_id'], $row['transaction_id']]);
            if (isset($existing[$key])) {
                return false;
            }

            $existing[$key] = true;

            return true;
        }));

        if (!$dryRun && [] !== $rows) {
            $connection->transactional(static function (Connection $connection) use ($indexTable, $rows): void {
                foreach ($rows as $row) {
                    $connection->insert($indexTable, $row);
                }
            });
        }

        // unreadable diffs of the tables a path goes through count with the first table that reads them
        $unreadable += $this->unreadableInTimelines - $unreadableBefore;

        return ['entries' => $entries, 'rows' => \count($rows), 'legacy' => $legacy, 'unreadable' => $unreadable];
    }

    /**
     * Value of an association of an object at a position of the history, from its audit table.
     *
     * @return null|array{string, string} class and id of the associated object
     */
    private function valueAt(string $class, string $id, string $field, string $position): ?array
    {
        $timeline = $this->timeline($class, $field)[$id] ?? [];

        $value = null;
        foreach ($timeline as [$at, $candidate]) {
            if (strcmp($at, $position) > 0) {
                break;
            }

            $value = $candidate;
        }

        return $value;
    }

    /**
     * @return array<string, list<array{string, ?array{string, string}}>>
     */
    private function timeline(string $class, string $field): array
    {
        if (isset($this->timelines[$class][$field])) {
            return $this->timelines[$class][$field];
        }

        $timeline = [];
        $entities = $this->configuration()->getEntities();
        $auditTable = $entities[$class]['computed_audit_table_name'] ?? null;
        if (\is_string($auditTable) && $this->provider->hasAuditTable($class)) {
            $connection = $this->provider->getStorageServiceForEntity($class)->getEntityManager()->getConnection();
            $result = $connection->executeQuery(\sprintf('SELECT object_id, transaction_id, diffs, created_at, schema_version FROM %s WHERE type IN (?) ORDER BY created_at ASC, transaction_id ASC, id ASC', $auditTable), [['insert', 'update']], [ArrayParameterType::STRING]);
            foreach ($result->iterateAssociative() as $row) {
                if (2 > $this->schemaVersion($row)) {
                    continue;
                }

                $changes = $this->changes($row['diffs']);
                if (null === $changes) {
                    ++$this->unreadableInTimelines;

                    continue;
                }

                $change = $changes[$field] ?? null;
                if (\is_array($change) && \array_key_exists('new', $change)) {
                    $timeline[(string) $row['object_id']][] = [$row['created_at'].' '.$row['transaction_id'], $this->reference($change['new'])];
                }
            }
        }

        return $this->timelines[$class][$field] = $timeline;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function schemaVersion(array $row): int
    {
        return is_numeric($row['schema_version'] ?? null) ? (int) $row['schema_version'] : 1;
    }

    /**
     * @return null|array<array-key, mixed> the changes of a v2 diff, null when the diffs cannot be decoded
     */
    private function changes(mixed $diffs): ?array
    {
        if (!\is_string($diffs)) {
            return null;
        }

        try {
            $decoded = json_decode($diffs, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        return \is_array($decoded['changes'] ?? null) ? $decoded['changes'] : [];
    }

    /**
     * @return null|array{string, string} class and id of an association summary
     */
    private function reference(mixed $summary): ?array
    {
        if (!\is_array($summary) || !\is_string($summary['class'] ?? null)) {
            return null;
        }

        // the summary is keyed by the name of the primary key ("pkName"), "id" by default
        $key = \is_string($summary['pkName'] ?? null) ? $summary['pkName'] : 'id';

        return \is_scalar($summary[$key] ?? null) ? [$summary['class'], (string) $summary[$key]] : null;
    }

    /**
     * Root class of the inheritance hierarchy of $class, read from the entity manager of the audited $entity (the
     * root itself does not have to be audited).
     */
    private function rootClass(string $class, string $entity): string
    {
        $entityManager = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager();

        // a class renamed or deleted since the entry was written keeps its name
        if (!class_exists($class) || $entityManager->getMetadataFactory()->isTransient($class)) {
            return $class;
        }

        return $entityManager->getClassMetadata($class)->rootEntityName;
    }

    private function configuration(): Configuration
    {
        $configuration = $this->provider->getConfiguration();
        \assert($configuration instanceof Configuration);

        return $configuration;
    }
}
