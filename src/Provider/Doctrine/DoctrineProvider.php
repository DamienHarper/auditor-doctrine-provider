<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine;

use DH\Auditor\Auditor;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Exception\ProviderException;
use DH\Auditor\Provider\AbstractProvider;
use DH\Auditor\Provider\ConfigurationInterface;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\AttributeLoader;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditInsertBuffer;
use DH\Auditor\Provider\Doctrine\Auditing\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\Auditing\Transaction\TransactionManager;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Event\TableSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\ProviderInterface;
use DH\Auditor\Provider\Service\AuditingServiceInterface;
use DH\Auditor\Tests\Provider\Doctrine\DoctrineProviderTest;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\ToolEvents;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @see DoctrineProviderTest
 */
final class DoctrineProvider extends AbstractProvider implements LoggerAwareInterface, ResetInterface
{
    /**
     * Retention options that, when given in the configuration, take precedence over the #[Auditable] ones.
     */
    private const array RETENTION_OPTIONS = ['max_age', 'max_entries'];

    /**
     * Maps each column name to its 1-based bind position for prepared statements.
     * Computed once from AuditInsertBuffer::COLUMNS and cached for the process lifetime.
     *
     * @var null|array<string, int>
     */
    private static ?array $fieldIndex = null;

    private readonly TransactionManager $transactionManager;

    /** @var array<string, Statement> */
    private array $preparedStatements = [];

    /** @var DoctrineSubscriber[] */
    private array $subscribers = [];

    private ?ContainerInterface $diffLabelResolverLocator = null;

    private LoggerInterface $logger;

    /**
     * Buffer of the database transaction being committed, if any: with a positive `batch_size`, audit entries are
     * buffered instead of being inserted one by one.
     */
    private ?AuditInsertBuffer $activeBuffer = null;

    public function __construct(ConfigurationInterface $configuration)
    {
        $this->configuration = $configuration;
        $this->logger = new NullLogger();
        $this->transactionManager = new TransactionManager($this);

        \assert($this->configuration instanceof Configuration);    // helps PHPStan
        $this->configuration->setProvider($this);
    }

    public function getTransactionManager(): TransactionManager
    {
        return $this->transactionManager;
    }

    /**
     * Reports the failures that cannot be thrown without hiding the original one, e.g. audit entries stored on
     * another connection that could not be deleted after their transaction was rolled back.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setDiffLabelResolverLocator(ContainerInterface $locator): void
    {
        $this->diffLabelResolverLocator = $locator;
    }

    public function getDiffLabelResolverLocator(): ?ContainerInterface
    {
        return $this->diffLabelResolverLocator;
    }

    #[\Override]
    public function registerAuditingService(AuditingServiceInterface $service): ProviderInterface
    {
        parent::registerAuditingService($service);

        \assert($service instanceof AuditingService);    // helps PHPStan
        $entityManager = $service->getEntityManager();
        $evm = $entityManager->getEventManager();

        // Register audit listeners
        $evm->addEventListener([Events::loadClassMetadata], new TableSchemaListener());
        $evm->addEventListener([ToolEvents::postGenerateSchemaTable], new CreateSchemaListener($this));

        $doctrineSubscriber = new DoctrineSubscriber($this);
        $evm->addEventListener([Events::onFlush, Events::postFlush], $doctrineSubscriber);

        // Register soft delete listener if Gedmo SoftDeleteable is available
        if (class_exists(SoftDeleteableListener::class)) {
            $evm->addEventListener([SoftDeleteableListener::POST_SOFT_DELETE], $doctrineSubscriber);
        }

        $this->subscribers[] = $doctrineSubscriber;

        return $this;
    }

    /**
     * Resets the provider state for long-running processes (e.g. Symfony Messenger workers).
     *
     * Clears cached prepared statements that may reference stale database connections
     * after a connection reset, and resets all subscriber transaction caches.
     */
    public function reset(): void
    {
        // The buffer of a commit in progress is left in use: it is restored by the flusher processing that commit,
        // and without it an INSERT failure would be ignored by the auditor instead of failing the commit.
        $this->preparedStatements = [];

        foreach ($this->subscribers as $subscriber) {
            $subscriber->reset();
        }
    }

    public function isStorageMapperRequired(): bool
    {
        return \count($this->getStorageServices()) > 1;
    }

    public function getAuditingServiceForEntity(string $entity): AuditingService
    {
        foreach ($this->auditingServices as $service) {
            \assert($service instanceof AuditingService);   // helps PHPStan

            try {
                // entity is managed by the entity manager of this service
                $service->getEntityManager()->getClassMetadata($entity)->getTableName();

                return $service;
            } catch (\Exception) {
            }
        }

        throw new InvalidArgumentException(\sprintf('Auditing service not found for "%s".', $entity));
    }

    public function getStorageServiceForEntity(string $entity): StorageService
    {
        $this->checkStorageMapper();

        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        $storageMapper = $this->configuration->getStorageMapper();

        if (null === $storageMapper || 1 === \count($this->getStorageServices())) {
            // No mapper and only 1 storage entity manager
            /** @var array<StorageService> $services */
            $services = $this->getStorageServices();

            return array_values($services)[0];
        }

        if (\is_string($storageMapper) && class_exists($storageMapper)) {
            $storageMapper = new $storageMapper();
        }

        \assert(\is_callable($storageMapper));   // helps PHPStan

        return $storageMapper($entity, $this->getStorageServices());
    }

    /**
     * Sets the buffer of the database transaction being committed (null when no commit is in progress) and returns
     * the previous one, to restore once that commit is processed: a commit may happen while another one is processed.
     *
     * @internal
     */
    public function useInsertBuffer(?AuditInsertBuffer $buffer): ?AuditInsertBuffer
    {
        $previous = $this->activeBuffer;
        $this->activeBuffer = $buffer;

        return $previous;
    }

    public function persist(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();
        $auditTable = $payload['table'];
        $entity = $payload['entity'];
        unset($payload['table'], $payload['entity']);

        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        $batchSize = $this->configuration->getBatchSize();
        $buffer = $this->activeBuffer;
        if ($batchSize > 0 && $buffer instanceof AuditInsertBuffer) {
            // written when the database transaction is committed: the id of the audit entry is not known yet
            try {
                $connection = $this->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
                $buffer->add($connection, $auditTable, $this->row($payload), $batchSize);
            } catch (\Throwable $throwable) {
                // the auditor ignores this exception: the buffer reports it when the transaction is committed
                $buffer->fail($throwable);
            }

            return;
        }

        $storageService = $this->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();

        $cacheKey = spl_object_id($connection).'.'.$auditTable;

        if (!isset($this->preparedStatements[$cacheKey])) {
            // PostgreSQL requires an explicit ::jsonb cast for jsonb columns in prepared statements
            // (the driver otherwise infers the placeholder type as text, causing a type mismatch).
            $jsonCast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';
            $keys = AuditInsertBuffer::COLUMNS;
            $placeholders = array_map(
                static fn (string $col): string => \in_array($col, AuditInsertBuffer::JSON_COLUMNS, true)
                    ? '?'.$jsonCast
                    : '?',
                $keys
            );
            $query = \sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $auditTable,
                implode(', ', $keys),
                implode(', ', $placeholders)
            );
            $this->preparedStatements[$cacheKey] = $connection->prepare($query);
        }

        $statement = $this->preparedStatements[$cacheKey];

        $fieldIndex = self::$fieldIndex ??= array_map(
            static fn (int $i): int => $i + 1,
            array_flip(AuditInsertBuffer::COLUMNS)
        );

        foreach ($payload as $key => $value) {
            $statement->bindValue($fieldIndex[$key], $value);
        }

        $statement->executeStatement();

        // let's get the last inserted ID from the database so other providers can use that info
        $payload = $event->getPayload();
        $payload['id'] = (int) $storageService->getEntityManager()->getConnection()->lastInsertId();
        $event->setPayload($payload);
    }

    /**
     * Returns true if $entity is auditable.
     */
    public function isAuditable(object|string $entity): bool
    {
        $class = DoctrineHelper::getRealClassName($entity);
        // is $entity part of audited entities?
        \assert($this->configuration instanceof Configuration);   // helps PHPStan

        // no => $entity is not audited
        return \array_key_exists($class, $this->configuration->getEntities());
    }

    /**
     * Returns true if $entity is auditable and has an audit table.
     *
     * An auditable entity has no audit table when the "skip_disabled_entities" option is on and the entity is
     * disabled by its configuration (e.g. #[Auditable(enabled: false)]).
     */
    public function hasAuditTable(object|string $entity): bool
    {
        if (!$this->isAuditable($entity)) {
            return false;
        }

        \assert($this->configuration instanceof Configuration);   // helps PHPStan

        return !$this->configuration->isAuditTableSkipped(DoctrineHelper::getRealClassName($entity));
    }

    /**
     * Returns true if $entity is audited.
     */
    public function isAudited(object|string $entity): bool
    {
        \assert($this->auditor instanceof Auditor);
        if (!$this->auditor->getConfiguration()->enabled) {
            return false;
        }

        /** @var Configuration $configuration */
        $configuration = $this->configuration;
        $class = DoctrineHelper::getRealClassName($entity);

        // is $entity part of audited entities?
        $entities = $configuration->getEntities();
        if (!\array_key_exists($class, $entities)) {
            // no => $entity is not audited
            return false;
        }

        $entityOptions = $entities[$class];

        if (isset($entityOptions['enabled'])) {
            return (bool) $entityOptions['enabled'];
        }

        return true;
    }

    /**
     * Returns true if $field is audited.
     */
    public function isAuditedField(object|string $entity, string $field): bool
    {
        // is $field is part of globally ignored columns?
        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        if (\in_array($field, $this->configuration->getIgnoredColumns(), true)) {
            // yes => $field is not audited
            return false;
        }

        // is $entity audited?
        if (!$this->isAudited($entity)) {
            // no => $field is not audited
            return false;
        }

        $class = DoctrineHelper::getRealClassName($entity);
        $entityOptions = $this->configuration->getEntities()[$class];

        // are columns excluded and is field part of them?
        // yes => $field is not audited
        return !isset($entityOptions['ignored_columns']) || !\in_array($field, $entityOptions['ignored_columns'], true);
    }

    public function supportsStorage(): bool
    {
        return true;
    }

    public function supportsAuditing(): bool
    {
        return true;
    }

    public function setStorageMapper(callable $storageMapper): void
    {
        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        $this->configuration->setStorageMapper($storageMapper);
    }

    public function loadAttributes(EntityManagerInterface $entityManager, array $entities): self
    {
        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        $ormConfiguration = $entityManager->getConfiguration();
        $metadataCache = $ormConfiguration->getMetadataCache();

        $attributeLoader = new AttributeLoader($entityManager);

        if ($metadataCache instanceof CacheItemPoolInterface) {
            $item = $metadataCache->getItem('__DH_ATTRIBUTES__');
            if (!$item->isHit() || !\is_array($attributeEntities = $item->get())) {
                $attributeEntities = $attributeLoader->load();
                $item->set($attributeEntities);
                $metadataCache->save($item);
            }
        } else {
            $attributeEntities = $attributeLoader->load();
        }

        $merged = array_merge($entities, $attributeEntities);

        // Retention settings given in the configuration (e.g. bundle YAML) take precedence over the attribute ones,
        // key by key, so that they can be overridden per environment (an explicit null disables the attribute value).
        foreach (array_keys($attributeEntities) as $entity) {
            if (!\is_array($entities[$entity] ?? null)) {
                continue;
            }

            if (!\is_array($merged[$entity])) {
                continue;
            }

            foreach (self::RETENTION_OPTIONS as $option) {
                if (\array_key_exists($option, $entities[$entity])) {
                    $merged[$entity][$option] = $entities[$entity][$option];
                }
            }
        }

        $this->configuration->setEntities($merged);

        return $this;
    }

    /**
     * Values of the audit entry, ordered like AuditInsertBuffer::COLUMNS.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<mixed>
     */
    private function row(array $payload): array
    {
        $row = [];
        foreach (AuditInsertBuffer::COLUMNS as $column) {
            if (!\array_key_exists($column, $payload)) {
                // LifecycleEvent requires every column it knows of: guards against a column it does not require yet
                throw new \LogicException(\sprintf('The audit entry has no "%s" value.', $column));
            }

            $row[] = $payload[$column];
        }

        return $row;
    }

    private function checkStorageMapper(): self
    {
        \assert($this->configuration instanceof Configuration);   // helps PHPStan
        if (null === $this->configuration->getStorageMapper() && $this->isStorageMapperRequired()) {
            throw new ProviderException('You must provide a mapper callback to map audits to storage.');
        }

        return $this;
    }
}
