<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Retention;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Retention\RetentionPrunerTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Deletes audit entries according to per-entity retention policies.
 *
 * For a given entity, an audit entry is deleted when:
 * - it was created before the cutoff: the entity's own "max_age" when set, the default cutoff otherwise
 *   (no default cutoff and no "max_age" means no age-based deletion);
 * - or it is not among the "max_entries" most recent entries of the entity (when set).
 *
 * On a single-table inheritance hierarchy, the classes share one audit table: every statement is then
 * restricted to the entity's own entries through the "discriminator" column, so that the policy of a class
 * never deletes entries of another one.
 *
 * Rows of the audit root index that no longer match an entry of the table are deleted along with the entries.
 *
 * @see RetentionPrunerTest
 */
final readonly class RetentionPruner
{
    public const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private DoctrineProvider $provider) {}

    /**
     * Returns "now" in the timezone audit entries are written in.
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->getTimezone());
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->provider->getAuditor()->getConfiguration()->timezone);
    }

    /**
     * Returns every auditable entity class, across all storage services.
     *
     * @return list<string>
     */
    public function getAuditableEntities(): array
    {
        $entities = [];
        foreach (new SchemaManager($this->provider)->collectAuditableEntities() as $classes) {
            \assert(\is_array($classes));
            foreach (array_keys($classes) as $entity) {
                $entities[] = (string) $entity;
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * Returns the non-empty retention policies of the given entities, keyed by entity class.
     *
     * Every entity is checked before returning, so that an invalid configuration is reported as a whole
     * and fails before any deletion.
     *
     * @param iterable<string> $entities
     *
     * @return array<string, RetentionPolicy>
     *
     * @throws InvalidArgumentException listing every invalid policy
     */
    public function getPolicies(iterable $entities): array
    {
        $configuration = $this->provider->getConfiguration();
        \assert($configuration instanceof Configuration);
        $options = $configuration->getEntities();

        $policies = [];
        $errors = [];
        foreach ($entities as $entity) {
            $entityOptions = $options[$entity] ?? [];
            $maxAge = \is_array($entityOptions) ? ($entityOptions['max_age'] ?? null) : null;
            $maxEntries = \is_array($entityOptions) ? ($entityOptions['max_entries'] ?? null) : null;

            if (null !== $maxAge && !\is_string($maxAge)) {
                $errors[] = \sprintf('Entity "%s": "max_age" must be a string, %s given.', $entity, get_debug_type($maxAge));

                continue;
            }

            if (null !== $maxEntries && !\is_int($maxEntries)) {
                $errors[] = \sprintf('Entity "%s": "max_entries" must be an integer, %s given.', $entity, get_debug_type($maxEntries));

                continue;
            }

            try {
                $policy = new RetentionPolicy($maxAge, $maxEntries);
            } catch (InvalidArgumentException $exception) {
                $errors[] = \sprintf('Entity "%s": %s', $entity, $exception->getMessage());

                continue;
            }

            if (!$policy->isEmpty()) {
                $policies[$entity] = $policy;
            }
        }

        if ([] !== $errors) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return $policies;
    }

    /**
     * Deletes the audit entries of an entity selected by its retention rules (see class description).
     *
     * @param null|\DateTimeImmutable $defaultCutoff cutoff applied when the entity has no "max_age"; null leaves its entries untouched
     * @param bool                    $dryRun        when true, statements are computed but not executed
     *
     * @return list<string> the DELETE statements, parameters inlined, for display purposes
     */
    public function pruneEntity(string $entity, ?RetentionPolicy $policy, ?\DateTimeImmutable $defaultCutoff, bool $dryRun = false): array
    {
        $configuration = $this->provider->getConfiguration();
        \assert($configuration instanceof Configuration);

        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $auditTable = new SchemaManager($this->provider)->resolveAuditTableName($entity, $configuration);
        \assert(\is_string($auditTable));
        $discriminator = $this->isSingleTableInheritance($entity) ? $entity : null;

        $statements = [];

        // Age-based deletion
        $cutoff = $policy?->getCutoff($this->now()) ?? $defaultCutoff;
        if ($cutoff instanceof \DateTimeImmutable) {
            $queryBuilder = $connection->createQueryBuilder()
                ->delete($auditTable)
                ->where('created_at < :until')
                ->setParameter('until', $cutoff->setTimezone($this->getTimezone())->format(self::DATE_FORMAT))
            ;
            $this->restrictToDiscriminator($queryBuilder, $discriminator);

            $statements[] = $this->execute($queryBuilder, $connection, $dryRun);
        }

        // Count-based deletion: keep the "max_entries" most recent entries (most recent first, ties broken by id)
        if (null !== $policy?->maxEntries) {
            $boundaryQuery = $connection->createQueryBuilder()
                ->select('created_at', 'id')
                ->from($auditTable)
                ->orderBy('created_at', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setFirstResult($policy->maxEntries)
                ->setMaxResults(1)
            ;
            $this->restrictToDiscriminator($boundaryQuery, $discriminator);

            // The most recent entry beyond the "max_entries" kept ones; none means nothing to delete.
            $boundary = $boundaryQuery->executeQuery()->fetchAssociative();
            if (false !== $boundary) {
                \assert(\is_string($boundary['created_at']));
                \assert(is_numeric($boundary['id']));

                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder
                    ->delete($auditTable)
                    ->where($queryBuilder->expr()->or(
                        'created_at < :boundary_created_at',
                        $queryBuilder->expr()->and('created_at = :boundary_created_at', 'id <= :boundary_id'),
                    ))
                    ->setParameter('boundary_created_at', $boundary['created_at'])
                    ->setParameter('boundary_id', (int) $boundary['id'], ParameterType::INTEGER)
                ;
                $this->restrictToDiscriminator($queryBuilder, $discriminator);

                $statements[] = $this->execute($queryBuilder, $connection, $dryRun);
            }
        }

        if ([] !== $statements && $this->hasRootIndex($connection, $configuration)) {
            $statements[] = $this->pruneRootIndex($connection, $configuration->getRootIndexTableName(), $auditTable, $dryRun);
        }

        return $statements;
    }

    /**
     * Deletes the index rows of an audit table whose entry is gone.
     */
    private function pruneRootIndex(Connection $connection, string $indexTable, string $auditTable, bool $dryRun): string
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->delete($indexTable)
            ->where('audit_table = :audit_table')
            ->andWhere(\sprintf(
                'NOT EXISTS (SELECT 1 FROM %1$s e WHERE e.object_id = %2$s.object_id AND e.transaction_id = %2$s.transaction_id)',
                $auditTable,
                $indexTable,
            ))
            ->setParameter('audit_table', $auditTable)
        ;

        return $this->execute($queryBuilder, $connection, $dryRun);
    }

    private function hasRootIndex(Connection $connection, Configuration $configuration): bool
    {
        return $configuration->hasRoots() && $connection->createSchemaManager()->tablesExist([$configuration->getRootIndexTableName()]);
    }

    private function isSingleTableInheritance(string $entity): bool
    {
        $metadata = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);

        return ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType;
    }

    private function restrictToDiscriminator(QueryBuilder $queryBuilder, ?string $discriminator): void
    {
        if (null !== $discriminator) {
            $queryBuilder
                ->andWhere('discriminator = :discriminator')
                ->setParameter('discriminator', $discriminator)
            ;
        }
    }

    private function execute(QueryBuilder $queryBuilder, Connection $connection, bool $dryRun): string
    {
        if (!$dryRun) {
            $queryBuilder->executeStatement();
        }

        $sql = $queryBuilder->getSQL();
        foreach ($queryBuilder->getParameters() as $name => $value) {
            \assert(\is_string($name));
            \assert(\is_int($value) || \is_string($value));
            $literal = \is_int($value) ? (string) $value : $connection->quote($value);
            $sql = (string) preg_replace('/:'.preg_quote($name, '/').'\b/', $literal, $sql);
        }

        return $sql;
    }
}
