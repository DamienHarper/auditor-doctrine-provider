<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine;

use DH\Auditor\Provider\ConfigurationInterface;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Tests\Provider\Doctrine\ConfigurationTest;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @see ConfigurationTest
 */
final class Configuration implements ConfigurationInterface
{
    private ?DoctrineProvider $provider = null;

    private readonly string $tablePrefix;

    private readonly string $tableSuffix;

    /**
     * @var array<string>
     */
    private readonly array $ignoredColumns;

    private ?array $entities = null;

    private bool $isViewerEnabled;

    private int $viewerPageSize;

    private readonly bool $utf8Convert;

    private readonly bool $fullSnapshotOnRemove;

    private readonly int $batchSize;
    private readonly bool $compression;

    private readonly bool $skipDisabledEntities;

    /**
     * Entities disabled by their configuration (before any runtime change), when "skip_disabled_entities" is on.
     *
     * @var null|array<string, true>
     */
    private ?array $skippedEntities = null;

    private bool $initialized = false;

    /**
     * @var null|callable
     */
    private $storageMapper;

    /**
     * @var null|callable(object): mixed
     */
    private $ignoredColumnsResolver;

    private array $attributeLoaded = [];

    public function __construct(array $options)
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $config = $resolver->resolve($options);

        $this->tablePrefix = $config['table_prefix'];
        $this->tableSuffix = $config['table_suffix'];
        $this->ignoredColumns = $config['ignored_columns'];

        if (isset($config['entities']) && !empty($config['entities'])) {
            // use entity names as array keys for easier lookup
            foreach ($config['entities'] as $auditedEntity => $entityOptions) {
                $this->entities[$auditedEntity] = $entityOptions;
            }
        }

        $this->isViewerEnabled = self::isViewerEnabledInConfig($config['viewer']);
        $this->viewerPageSize = self::getViewerPageSizeFromConfig($config['viewer']);
        $this->utf8Convert = $config['utf8_convert'];
        $this->fullSnapshotOnRemove = $config['full_snapshot_on_remove'];
        $this->batchSize = $config['batch_size'];
        $this->skipDisabledEntities = $config['skip_disabled_entities'];
        $this->compression = $config['compression'];
        $this->storageMapper = $config['storage_mapper'];
        $this->ignoredColumnsResolver = $config['ignored_columns_resolver'];
    }

    public static function isViewerEnabledInConfig(mixed $config): bool
    {
        if (\is_array($config)) {
            if (!\array_key_exists('enabled', $config) || !\is_bool($config['enabled'])) {
                return false;
            }

            return $config['enabled'];
        }

        // "viewer" is disabled by default
        return \is_bool($config) && $config;
    }

    public static function getViewerPageSizeFromConfig(mixed $config): int
    {
        if (\is_array($config)) {
            if (!\array_key_exists('page_size', $config) || !\is_int($config['page_size'])) {
                return Reader::PAGE_SIZE;
            }

            return abs($config['page_size']);
        }

        return \is_int($config) ? abs($config) : Reader::PAGE_SIZE;
    }

    /**
     * Set the value of entities.
     *
     * This method completely overrides entities configuration
     * including attribute configuration
     *
     * @param array<int|string, mixed> $entities
     */
    public function setEntities(array $entities): self
    {
        $this->entities = $entities;
        $this->initialized = false;
        $this->skippedEntities = null;

        return $this;
    }

    /**
     * enable audit Controller and its routing.
     *
     * @return $this
     */
    public function enableViewer(): self
    {
        $this->isViewerEnabled = true;

        return $this;
    }

    /**
     * disable audit Controller and its routing.
     *
     * @return $this
     */
    public function disableViewer(): self
    {
        $this->isViewerEnabled = false;

        return $this;
    }

    public function setViewerPageSize(int $pageSize): self
    {
        $this->viewerPageSize = abs($pageSize);

        return $this;
    }

    public function getViewerPageSize(): int
    {
        return $this->viewerPageSize;
    }

    /**
     * Get enabled flag.
     */
    public function isViewerEnabled(): bool
    {
        return $this->isViewerEnabled;
    }

    public function isUtf8ConvertEnabled(): bool
    {
        return $this->utf8Convert;
    }

    /**
     * Whether to capture the full field snapshot of a removed entity.
     *
     * When enabled, the audit entry for a REMOVE operation stores the complete state of the
     * entity before deletion (all audited fields with old: <value>, new: null). This allows
     * reconstructing what was deleted from the audit log alone.
     *
     * Disabled by default because iterating all field mappings via reflection has a measurable
     * cost on entities with many fields. Enable only when audit trail completeness is required.
     */
    public function isFullSnapshotOnRemoveEnabled(): bool
    {
        return $this->fullSnapshotOnRemove;
    }

    /**
     * Number of buffered audit entries that triggers writing them with multi-row INSERT statements.
     *
     * 0 (the default) writes each audit entry with its own INSERT, as soon as it is produced. A positive value
     * buffers the audit entries of the transaction being committed and writes them with multi-row INSERT statements
     * right before the COMMIT, or as soon as this many entries are buffered; a statement holds at most the
     * per-platform limit of rows.
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Whether entities disabled by their configuration (e.g. #[Auditable(enabled: false)]) get no audit table.
     *
     * By default, such entities still get an audit table, since auditing can be enabled for them at runtime with
     * enableAuditFor(). With this option, audit:schema:update, doctrine:schema:update and Doctrine Migrations skip
     * them, and enableAuditFor() refuses to enable them.
     */
    public function isSkipDisabledEntitiesEnabled(): bool
    {
        return $this->skipDisabledEntities;
    }

    /**
     * Whether the given entity has no audit table: "skip_disabled_entities" is on and the entity is disabled by its
     * configuration. Runtime changes made with enableAuditFor() / disableAuditFor() do not change the answer.
     */
    public function isAuditTableSkipped(string $entity): bool
    {
        if (!$this->skipDisabledEntities) {
            return false;
        }

        return isset($this->getSkippedEntities()[$entity]);
    }

    /**
     * Whether the large JSON columns of audit tables (diffs, extra_data) are stored compressed.
     *
     * Only effective on MariaDB, where these columns are declared with the COMPRESSED attribute
     * (transparent column compression). Ignored on other platforms: PostgreSQL already compresses
     * large JSONB values (TOAST), MySQL and SQLite have no column-level compression.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    /**
     * Get the value of tablePrefix.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Get the value of tableSuffix.
     */
    public function getTableSuffix(): string
    {
        return $this->tableSuffix;
    }

    /**
     * Properties that are not audited, for every entity.
     *
     * @return array<string>
     */
    public function getIgnoredColumns(): array
    {
        return $this->ignoredColumns;
    }

    /**
     * Sets the callable that returns, for a given entity object, the properties that are not audited
     * (see IgnoredColumnsResolverInterface), or removes it with null.
     *
     * @param null|callable(object): array<string> $resolver
     */
    public function setIgnoredColumnsResolver(?callable $resolver): self
    {
        $this->ignoredColumnsResolver = $resolver;

        return $this;
    }

    public function getIgnoredColumnsResolver(): ?callable
    {
        return $this->ignoredColumnsResolver;
    }

    /**
     * Returns the properties of $entity that the "ignored_columns_resolver" ignores ([] without resolver).
     *
     * @return list<string>
     *
     * @throws \UnexpectedValueException when the resolver does not return a list of property names
     */
    public function resolveIgnoredColumns(object $entity): array
    {
        if (null === $this->ignoredColumnsResolver) {
            return [];
        }

        $resolved = ($this->ignoredColumnsResolver)($entity);
        if (!\is_array($resolved)) {
            throw new \UnexpectedValueException(\sprintf('The ignored columns resolver must return an array of property names, %s returned for "%s".', get_debug_type($resolved), $entity::class));
        }

        $columns = [];
        foreach ($resolved as $column) {
            if (!\is_string($column)) {
                throw new \UnexpectedValueException(\sprintf('The ignored columns resolver must return an array of property names, an array holding %s returned for "%s".', get_debug_type($column), $entity::class));
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Get the value of entities.
     */
    public function getEntities(): array
    {
        if ($this->initialized && null !== $this->entities) {
            return $this->entities;
        }

        if ($this->provider instanceof DoctrineProvider) {
            $schemaManager = new SchemaManager($this->provider);

            /** @var array<AuditingService> $auditingServices */
            $auditingServices = $this->provider->getAuditingServices();
            foreach ($auditingServices as $auditingService) {
                $entityManager = $auditingService->getEntityManager();
                $platform = $entityManager->getConnection()->getDatabasePlatform();

                // do not load attributes if they're already loaded
                if (!isset($this->attributeLoaded[$auditingService->getName()]) || !$this->attributeLoaded[$auditingService->getName()]) {
                    $this->provider->loadAttributes($entityManager, $this->entities ?? []);
                    $this->attributeLoaded[$auditingService->getName()] = true;
                }

                \assert(null !== $this->entities);

                /**
                 * In multi-EM setups (e.g. Symfony with MappingDriverChain), each EM's
                 * metadata driver only handles its own namespace. Calling getClassMetadata()
                 * for an entity that belongs to a different EM's namespace causes Doctrine
                 * to throw a MappingException ("not found in the chain configured namespaces").
                 * isTransient() returns true (without throwing) for classes not handled by
                 * the current EM's driver, so we use it as a lightweight guard.
                 * Unlike getAllMetadata()-based filtering, isTransient() works via reflection
                 * for AttributeDriver and via namespace prefix for MappingDriverChain, so it
                 * correctly handles entities that are not in the EM's scan paths but are still
                 * manageable (e.g. entities in custom fixture directories).
                 *
                 * @see https://github.com/DamienHarper/auditor/issues/281
                 */
                $metadataDriver = $entityManager->getConfiguration()->getMetadataDriverImpl();

                foreach (array_keys($this->entities) as $entity) {
                    $realClass = DoctrineHelper::getRealClassName($entity);

                    // Skip entities not mapped in this entity manager.
                    if ($metadataDriver instanceof MappingDriver && $metadataDriver->isTransient($realClass)) {
                        continue;
                    }

                    $meta = $entityManager->getClassMetadata($realClass);
                    $entityTableName = $meta->getTableName();
                    $namespaceName = $meta->getSchemaName() ?? '';

                    $computedTableName = $schemaManager->resolveTableName($entityTableName, $namespaceName);
                    $this->entities[$entity]['table_schema'] = $namespaceName;
                    $this->entities[$entity]['table_name'] = $entityTableName;
                    // $this->entities[$entity]['computed_table_name'] = $entityTableName;
                    $this->entities[$entity]['computed_table_name'] = $computedTableName;
                    $this->entities[$entity]['audit_table_schema'] = $namespaceName;
                    $this->entities[$entity]['audit_table_name'] = $schemaManager->computeAuditTablename($entityTableName, $this);
                    // $this->entities[$entity]['computed_audit_table_name'] = $schemaManager->computeAuditTablename($this->entities[$entity], $this, $platform);
                    $this->entities[$entity]['computed_audit_table_name'] = $schemaManager->computeAuditTablename(
                        $computedTableName,
                        $this
                    );
                }
            }

            $this->initialized = true;
        }

        return $this->entities ?? [];
    }

    /**
     * Enables auditing for a specific entity.
     *
     * @param string $entity Entity class name
     *
     * @return $this
     */
    public function enableAuditFor(string $entity): self
    {
        if ($this->isAuditTableSkipped($entity)) {
            throw new \LogicException(\sprintf('Auditing cannot be enabled for "%s": it is disabled by its configuration and has no audit table since the "skip_disabled_entities" option is on.', $entity));
        }

        if (isset($this->getEntities()[$entity])) {
            $this->entities[$entity]['enabled'] = true;
        }

        return $this;
    }

    /**
     * Disables auditing for a specific entity.
     *
     * @param string $entity Entity class name
     *
     * @return $this
     */
    public function disableAuditFor(string $entity): self
    {
        if ($this->skipDisabledEntities) {
            // snapshot the configured state before changing it
            $this->getSkippedEntities();
        }

        if (isset($this->getEntities()[$entity])) {
            $this->entities[$entity]['enabled'] = false;
        }

        return $this;
    }

    public function setStorageMapper(callable $mapper): self
    {
        $this->storageMapper = $mapper;

        return $this;
    }

    /**
     * @return null|callable|string
     */
    public function getStorageMapper(): mixed
    {
        return $this->storageMapper;
    }

    public function getProvider(): ?DoctrineProvider
    {
        return $this->provider;
    }

    public function setProvider(DoctrineProvider $provider): void
    {
        $this->provider = $provider;
        $this->initialized = false;
    }

    /**
     * @return array<string, true>
     */
    private function getSkippedEntities(): array
    {
        if (null !== $this->skippedEntities) {
            return $this->skippedEntities;
        }

        $skipped = [];
        foreach ($this->getEntities() as $entity => $options) {
            if (\is_array($options) && \array_key_exists('enabled', $options) && false === $options['enabled']) {
                $skipped[(string) $entity] = true;
            }
        }

        // only keep the snapshot once attributes are loaded, so that it reflects the whole configuration
        if ($this->initialized) {
            $this->skippedEntities = $skipped;
        }

        return $skipped;
    }

    private function configureOptions(OptionsResolver $resolver): void
    {
        // https://symfony.com/doc/current/components/options_resolver.html
        $resolver
            ->setDefaults([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
                'storage_services' => [],
                'auditing_services' => [],
                'viewer' => true,
                'storage_mapper' => null,
                'utf8_convert' => false,
                'full_snapshot_on_remove' => false,
                'batch_size' => 0,
                'skip_disabled_entities' => false,
                'compression' => false,
                'ignored_columns_resolver' => null,
            ])
            ->setAllowedTypes('table_prefix', 'string')
            ->setAllowedTypes('table_suffix', 'string')
            ->setAllowedTypes('ignored_columns', 'array')
            ->setAllowedTypes('entities', 'array')
            ->setAllowedTypes('storage_services', 'array')
            ->setAllowedTypes('auditing_services', 'array')
            ->setAllowedTypes('viewer', ['bool', 'array'])
            ->setAllowedTypes('storage_mapper', ['null', 'string', 'callable'])
            ->setAllowedTypes('utf8_convert', 'bool')
            ->setAllowedTypes('full_snapshot_on_remove', 'bool')
            ->setAllowedTypes('batch_size', 'int')
            ->setAllowedValues('batch_size', static fn (int $value): bool => $value >= 0)
            ->setAllowedTypes('skip_disabled_entities', 'bool')
            ->setAllowedTypes('compression', 'bool')
            ->setAllowedTypes('ignored_columns_resolver', ['null', 'callable'])
        ;
    }
}
