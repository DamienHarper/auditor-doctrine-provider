<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\ChangeKind;
use DH\Auditor\Model\Entry;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\BlameOriginFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ChangedFieldFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RootFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\ReaderTest;
use Doctrine\ORM\Mapping\ClassMetadata as ORMMetadata;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @see ReaderTest
 */
final readonly class Reader implements ReaderInterface
{
    public const int PAGE_SIZE = 50;

    /**
     * Reader constructor.
     */
    public function __construct(private DoctrineProvider $provider) {}

    public function getProvider(): DoctrineProvider
    {
        return $this->provider;
    }

    public function createQuery(string $entity, array $options = []): Query
    {
        return $this->buildQuery($entity, $options, true);
    }

    /**
     * Creates the query of the history of an audit root: the entries of the root itself and of the entities indexed
     * under it (see #[AuditRoot]), most recent first.
     *
     * Only the "view" role of the root entity is checked: reading the history of a root gives access to the entries
     * of the entities indexed under it.
     *
     * Options:
     * - include: FQCNs of the entities to read (all by default)
     * - paths: root paths to keep (all by default)
     * - self: whether to read the entries of the root itself (true by default)
     * - type, blame_id, changed_field, blame_origin: same as createQuery()
     */
    public function createRootQuery(string $rootClass, int|string $rootId, array $options = []): RootQuery
    {
        $rootClass = $this->rootEntityName($rootClass);
        $this->checkRoles($rootClass, Security::VIEW_SCOPE);

        $resolver = new OptionsResolver();
        $resolver
            ->setDefaults([
                'include' => null,
                'paths' => [],
                'self' => true,
                'type' => null,
                'blame_id' => null,
                'changed_field' => null,
                'blame_origin' => null,
            ])
            ->setAllowedTypes('include', ['null', 'string[]'])
            ->setAllowedTypes('paths', 'string[]')
            ->setAllowedTypes('self', 'bool')
            ->setAllowedTypes('type', ['null', 'string', 'array'])
            ->setAllowedTypes('blame_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('changed_field', ['null', 'string', 'string[]'])
            ->setAllowedTypes('blame_origin', ['null', 'string', BlameOrigin::class, 'array'])
        ;
        $config = $resolver->resolve($options);

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $this->checkRootQueryOptions($configuration, $config['include'], $config['paths']);
        $queryOptions = [
            'type' => $config['type'],
            'blame_id' => $config['blame_id'],
            'changed_field' => $config['changed_field'],
            'blame_origin' => $config['blame_origin'],
            'page' => null,
            'page_size' => null,
            'strict' => false,
        ];

        /** @var list<string> $paths */
        $paths = array_values($config['paths']);
        $sources = [];
        $seen = [];
        foreach (array_keys($configuration->getEntities()) as $entity) {
            $entity = (string) $entity;
            if (!$this->provider->isAuditable($entity) || !$this->provider->hasAuditTable($entity)) {
                continue;
            }

            if (null !== $config['include'] && !\in_array($entity, $config['include'], true)) {
                continue;
            }

            $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
            $table = spl_object_id($connection).'.'.$this->getEntityAuditTableName($entity);

            // entries of the root itself: its audit table is read once, through the root of its inheritance hierarchy
            if ($config['self'] && [] === $paths && $this->rootEntityName($entity) === $rootClass && !isset($seen['self.'.$table])) {
                $seen['self.'.$table] = true;
                $query = $this->buildQuery($entity, ['object_id' => (string) $rootId] + $queryOptions, false);
                $sources[] = ['entity' => $this->tableEntity($entity), 'query' => $query];
            }

            // entries indexed under the root
            if ($configuration->hasRoots($entity) && !isset($seen['root.'.$table])) {
                $seen['root.'.$table] = true;
                $query = $this->buildQuery($entity, $queryOptions, false);
                $query->setHistoryRoot(new RootFilter($rootClass, $rootId, $paths));
                $sources[] = ['entity' => $this->tableEntity($entity), 'query' => $query];
            }
        }

        return new RootQuery($rootClass, (string) $rootId, $sources);
    }

    /**
     * Returns the last change of each field of an entity, or its creation (or snapshot) for a field never changed
     * since, or null when nothing was audited. Read the value of a field with Change::getValue($field), whatever the
     * kind of the change.
     *
     * Reads the entries that changed one of the fields when the platform supports JSON search, otherwise scans the
     * entries of the entity.
     *
     * @param list<string> $fields
     *
     * @return array<string, ?Change>
     */
    public function getLastChanges(string $entity, int|string $id, array $fields, array $options = []): array
    {
        $last = array_fill_keys($fields, null);
        if ([] === $fields) {
            return [];
        }

        $query = $this->buildQuery($entity, ['object_id' => (string) $id, 'page' => null, 'page_size' => null] + $options, true);
        if (JsonPlatformHelper::isJsonSearchSupported($query->getConnection())) {
            $query->addFilter(new ChangedFieldFilter($fields));
        }

        $extractor = new ChangeExtractor([$entity => $fields]);
        $missing = array_fill_keys($fields, true);
        foreach ($query->iterate() as $row) {
            $entry = $this->entry($row, $query, $entity);
            foreach ($extractor->extract($entry) as $change) {
                if (null !== $change->field && isset($missing[$change->field])) {
                    $last[$change->field] = $change;
                    unset($missing[$change->field]);
                }
            }

            if ([] === $missing) {
                return $last;
            }
        }

        // fields never changed: their value comes from the creation of the entity, or its snapshot
        $created = $this->buildQuery($entity, ['object_id' => (string) $id, 'type' => TransactionType::Insert->value, 'page' => 1, 'page_size' => 1], true);
        foreach ($created->iterate() as $row) {
            foreach ($extractor->extract($this->entry($row, $created, $entity)) as $change) {
                if (\in_array($change->kind, [ChangeKind::Created, ChangeKind::Snapshot], true)) {
                    foreach (array_keys($missing) as $field) {
                        $last[$field] = $change;
                    }
                }
            }
        }

        return $last;
    }

    /**
     * Creates the query of the entries that changed a field, of one entity or all entities of a class (needs a
     * platform that supports JSON search).
     */
    public function getFieldHistory(string $entity, int|string|null $id, string $field, array $options = []): Query
    {
        return $this->buildQuery($entity, ['object_id' => null === $id ? null : (string) $id, 'changed_field' => $field] + $options, true);
    }

    /**
     * Returns an array of all audited entries/operations for a given transaction ID
     * indexed by entity FQCN.
     *
     * Supports both schema v2 (transaction_id column) and legacy schema v1 (transaction_hash column).
     */
    public function getAuditsByTransactionId(string $transactionId): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $results = [];

        // Cache per audit table name to avoid repeated schema introspections.
        $transactionColumnCache = [];

        $entities = $configuration->getEntities();
        foreach (array_keys($entities) as $entity) {
            if (!$this->provider->hasAuditTable($entity)) {
                continue;
            }

            try {
                $auditTable = $this->getEntityAuditTableName($entity);

                // Detect which transaction column this table uses (once per table per request).
                if (!isset($transactionColumnCache[$auditTable])) {
                    $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
                    // only this table is introspected: the whole schema was, once per audit table
                    $transactionColumnCache[$auditTable] = \in_array(Query::TRANSACTION_ID, SchemaHelper::getTableColumnNames($connection, $auditTable), true)
                        ? Query::TRANSACTION_ID
                        : Query::LEGACY_TRANSACTION_HASH;
                }

                $transactionColumn = $transactionColumnCache[$auditTable];

                if (Query::TRANSACTION_ID === $transactionColumn) {
                    // Schema v2: use the standard createQuery path.
                    $audits = $this->createQuery($entity, ['transaction_id' => $transactionId, 'page_size' => null])->execute();
                } else {
                    // Schema v1 (legacy): transaction_id column does not exist yet — filter by transaction_hash.
                    $query = $this->createQuery($entity, ['page_size' => null]);
                    $query->addFilter(new SimpleFilter(Query::LEGACY_TRANSACTION_HASH, $transactionId));
                    $audits = $query->execute();
                }

                if ([] !== $audits) {
                    $results[$entity] = $audits;
                }
            } catch (AccessDeniedException) {
                // access denied
            }
        }

        return $results;
    }

    #[\Deprecated(message: 'Use getAuditsByTransactionId() instead')]
    public function getAuditsByTransactionHash(string $transactionHash): array
    {
        return $this->getAuditsByTransactionId($transactionHash);
    }

    /**
     * @return array{results: \ArrayIterator<int|string, Entry>, currentPage: int, hasPreviousPage: bool, hasNextPage: bool, previousPage: null|int, nextPage: null|int, numPages: int, haveToPaginate: bool, numResults: int, pageSize: int}
     */
    public function paginate(Query $query, int $page = 1, ?int $pageSize = null): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $pageSize ??= $configuration->getViewerPageSize();
        $numResults = $query->count();
        $currentPage = max(1, $page);
        $hasPreviousPage = $currentPage > 1;
        $hasNextPage = ($currentPage * $pageSize) < $numResults;

        return [
            'results' => new \ArrayIterator($query->limit($pageSize, ($currentPage - 1) * $pageSize)->execute()),
            'currentPage' => $currentPage,
            'hasPreviousPage' => $hasPreviousPage,
            'hasNextPage' => $hasNextPage,
            'previousPage' => $hasPreviousPage ? $currentPage - 1 : null,
            'nextPage' => $hasNextPage ? $currentPage + 1 : null,
            'numPages' => (int) ceil($numResults / $pageSize),
            'haveToPaginate' => $numResults > $pageSize,
            'numResults' => $numResults,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Returns the table name of $entity.
     */
    public function getEntityTableName(string $entity): string
    {
        $auditingService = $this->provider->getAuditingServiceForEntity($entity);

        return $auditingService->getEntityManager()->getClassMetadata($entity)->getTableName();
    }

    /**
     * Returns the audit table name for $entity.
     */
    public function getEntityAuditTableName(string $entity): string
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();

        // Use the pre-computed audit table name from configuration, which correctly handles
        // quoted identifiers (e.g. PostgreSQL reserved words like "user").
        // Direct string concatenation of prefix + tableName + suffix breaks when tableName
        // contains quotes, producing e.g. `"user"_audit` instead of `"user_audit"`.
        return $configuration->getEntities()[$entity]['computed_audit_table_name'];
    }

    private function buildQuery(string $entity, array $options, bool $checkRoles): Query
    {
        $this->checkAuditable($entity);
        if ($checkRoles) {
            $this->checkRoles($entity, Security::VIEW_SCOPE);
        }

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $config = $resolver->resolve($options);

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $timezone = $this->provider->getAuditor()->getConfiguration()->timezone;

        $query = new Query($this->getEntityAuditTableName($entity), $connection, $timezone);
        $query
            ->setRootIndexTable($configuration->getRootIndexTableName())
            ->addOrderBy(Query::CREATED_AT, 'DESC')
            ->addOrderBy(Query::ID, 'DESC')
        ;

        if (null !== $config['type']) {
            $query->addFilter(new SimpleFilter(Query::TYPE, $config['type']));
        }

        if (null !== $config['object_id']) {
            $query->addFilter(new SimpleFilter(Query::OBJECT_ID, $config['object_id']));
        }

        // user_id is considered as an alias for blame_id
        // if both are provided, blame_id will be used
        if (null !== $config['blame_id'] || null !== $config['user_id']) {
            $blame_id = $config['blame_id'] ?? $config['user_id'];
            $query->addFilter(new SimpleFilter(Query::USER_ID, $blame_id));
        }

        // Support both the new 'transaction_id' key and the deprecated 'transaction_hash' alias
        $transactionId = $config['transaction_id'] ?? $config['transaction_hash'];
        if (null !== $transactionId) {
            $query->addFilter(new SimpleFilter(Query::TRANSACTION_ID, $transactionId));
        }

        if (null !== $config['changed_field'] && [] !== $config['changed_field']) {
            $query->addFilter(new ChangedFieldFilter($config['changed_field']));
        }

        if (null !== $config['blame_origin'] && [] !== $config['blame_origin']) {
            $query->addFilter(new BlameOriginFilter($config['blame_origin']));
        }

        if (null !== $config['page'] && null !== $config['page_size']) {
            $query->limit($config['page_size'], ($config['page'] - 1) * $config['page_size']);
        }

        $auditingService = $this->provider->getAuditingServiceForEntity($entity);
        $metadata = $auditingService->getEntityManager()->getClassMetadata($entity);
        if (
            $config['strict']
            && $metadata instanceof ORMMetadata
            && ORMMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType
        ) {
            $query->addFilter(new SimpleFilter(Query::DISCRIMINATOR, $entity));
        }

        $scope = $configuration->getReadScope();
        if (null !== $scope) {
            $scope($query, $entity);
        }

        return $query;
    }

    /**
     * FQCN of the root class of the inheritance hierarchy of $entity, the class audit roots are indexed under.
     */
    private function rootEntityName(string $entity): string
    {
        try {
            $metadata = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an entity of an auditing entity manager.', $entity), 0, $invalidArgumentException);
        }

        return $metadata instanceof ORMMetadata ? $metadata->rootEntityName : $entity;
    }

    /**
     * An unknown entity in "include", or an unknown path in "paths", would silently read an empty history.
     *
     * @param null|list<string> $include
     * @param list<string>      $paths
     */
    private function checkRootQueryOptions(Configuration $configuration, ?array $include, array $paths): void
    {
        $entities = array_map(strval(...), array_keys($configuration->getEntities()));
        $unknown = array_diff($include ?? [], $entities);
        if ([] !== $unknown) {
            throw new InvalidArgumentException(\sprintf('Unknown audited entities in "include": %s.', implode(', ', $unknown)));
        }

        // paths given by a root resolver are free keys: they cannot be checked
        if ([] === $paths || null !== $configuration->getRootResolver()) {
            return;
        }

        $declared = [];
        foreach ($entities as $entity) {
            $declared = [...$declared, ...$configuration->getRoots($entity)];
        }

        $unknown = array_diff($paths, $declared);
        if ([] !== $unknown) {
            throw new InvalidArgumentException(\sprintf('Unknown root paths in "paths": %s.', implode(', ', $unknown)));
        }
    }

    /**
     * FQCN given to the entries of the audit table of $entity: its own, unless it shares the audit table of the root
     * of its single table inheritance hierarchy.
     */
    private function tableEntity(string $entity): string
    {
        $metadata = $this->provider->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);

        return $metadata instanceof ORMMetadata && ORMMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType ? $metadata->rootEntityName : $entity;
    }

    /**
     * @param array<string, mixed> $row raw row read by Query::iterate()
     */
    private function entry(array $row, Query $query, string $entity): Entry
    {
        \assert(\is_string($row['created_at']));
        $row['created_at'] = new \DateTimeImmutable($row['created_at'], $query->getTimezone());
        $row['entity'] = $entity;

        return Entry::fromArray($row);
    }

    private function configureOptions(OptionsResolver $resolver): void
    {
        // https://symfony.com/doc/current/components/options_resolver.html
        $resolver
            ->setDefaults([
                'type' => null,
                'object_id' => null,
                'blame_id' => null,
                'user_id' => null,
                'transaction_id' => null,
                'transaction_hash' => null,     // @deprecated use transaction_id
                'changed_field' => null,
                'blame_origin' => null,
                'page' => 1,
                'page_size' => self::PAGE_SIZE,
                'strict' => true,
            ])
            ->setAllowedTypes('type', ['null', 'string', 'array'])
            ->setAllowedTypes('object_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('blame_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('user_id', ['null', 'int', 'string', 'array'])
            ->setAllowedTypes('transaction_id', ['null', 'string', 'array'])
            ->setAllowedTypes('transaction_hash', ['null', 'string', 'array'])
            ->setAllowedTypes('changed_field', ['null', 'string', 'string[]'])
            ->setAllowedTypes('blame_origin', ['null', 'string', BlameOrigin::class, 'array'])
            ->setAllowedTypes('page', ['null', 'int'])
            ->setAllowedTypes('page_size', ['null', 'int'])
            ->setAllowedTypes('strict', ['null', 'bool'])
            ->setAllowedValues('page', static fn (?int $value): bool => null === $value || $value >= 1)
            ->setAllowedValues('page_size', static fn (?int $value): bool => null === $value || $value >= 1)
        ;
    }

    /**
     * Throws an InvalidArgumentException if given entity is not auditable.
     *
     * @throws InvalidArgumentException
     */
    private function checkAuditable(string $entity): void
    {
        if (!$this->provider->isAuditable($entity)) {
            throw new InvalidArgumentException('Entity '.$entity.' is not auditable.');
        }

        if (!$this->provider->hasAuditTable($entity)) {
            throw new InvalidArgumentException('Entity '.$entity.' has no audit table ("skip_disabled_entities" option).');
        }
    }

    /**
     * Throws an AccessDeniedException if user not is granted to access audits for the given entity.
     *
     * @throws AccessDeniedException
     */
    private function checkRoles(string $entity, string $scope): void
    {
        $roleChecker = $this->provider->getAuditor()->getConfiguration()->getRoleChecker();

        if (null === $roleChecker || $roleChecker($entity, $scope)) {
            return;
        }

        // access denied
        throw new AccessDeniedException('You are not allowed to access audits of "'.$entity.'" entity.');
    }
}
