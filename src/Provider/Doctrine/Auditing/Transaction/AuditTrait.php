<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Exception\MappingException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Transaction\NeedsConversionToAuditableType;
use DH\Auditor\User\UserInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\MappingException as ORMMappingException;

trait AuditTrait
{
    /**
     * Caches method_exists($entity, '__toString') result per class to avoid
     * repeated reflection on hot-path summarize() calls.
     */
    private static array $hasToStringCache = [];

    /**
     * Per-entity-class config cache (ignored and audited columns, diff_label_resolvers,
     * computed_audit_table_name). Populated lazily on first access per class
     * and shared across all flush operations for the lifetime of the provider.
     *
     * "ignored_columns" holds the global and the per-entity ignored properties as keys; "audited_columns"
     * holds the per-entity allowlist as keys, or null when every property is audited.
     *
     * @var array<string, array{computed_audit_table_name: string, ignored_columns: array<string, true>, audited_columns: null|array<string, true>, diff_label_resolvers: array<string, string>}>
     */
    private array $entityConfigCache = [];

    /**
     * Returns the cached configuration of an entity class.
     *
     * The configuration is set at bootstrap and never changes at runtime, so it is cached for the process lifetime.
     *
     * @return array{computed_audit_table_name: string, ignored_columns: array<string, true>, audited_columns: null|array<string, true>, diff_label_resolvers: array<string, string>}
     */
    private function entityConfig(string $entityClass): array
    {
        if (isset($this->entityConfigCache[$entityClass])) {
            return $this->entityConfigCache[$entityClass];
        }

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $entityCfg = $configuration->getEntities()[$entityClass] ?? [];
        if (!\is_array($entityCfg)) {
            $entityCfg = [];
        }

        $auditedColumns = $entityCfg['audited_columns'] ?? null;

        return $this->entityConfigCache[$entityClass] = [
            'computed_audit_table_name' => $entityCfg['computed_audit_table_name'] ?? '',
            'ignored_columns' => self::columnSet($configuration->getIgnoredColumns()) + self::columnSet($entityCfg['ignored_columns'] ?? []),
            'audited_columns' => null === $auditedColumns ? null : self::columnSet($auditedColumns),
            'diff_label_resolvers' => $entityCfg['diff_label_resolvers'] ?? [],
        ];
    }

    /**
     * Returns the properties of $entity that are ignored (as keys), and the allowlist of its audited properties
     * (as keys, null when every property not ignored is audited).
     *
     * A property is audited when it is not ignored (globally, for its entity class, by #[Ignore] or by the
     * "ignored_columns_resolver") and, when "audited_columns" is set for its entity class, is listed there:
     * an ignored property is never audited, even when it is allowlisted.
     *
     * @return array{array<string, true>, null|array<string, true>}
     */
    private function columnFilter(string $entityClass, object $entity): array
    {
        $entityConfig = $this->entityConfig($entityClass);
        $ignoredColumns = $entityConfig['ignored_columns'];

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        foreach ($configuration->resolveIgnoredColumns($entity) as $column) {
            $ignoredColumns[$column] = true;
        }

        return [$ignoredColumns, $entityConfig['audited_columns']];
    }

    /**
     * Turns a list of property names into a set (property names as keys); non-string values are dropped.
     *
     * @return array<string, true>
     */
    private static function columnSet(mixed $columns): array
    {
        $set = [];
        if (is_iterable($columns)) {
            foreach ($columns as $column) {
                if (\is_string($column)) {
                    $set[$column] = true;
                }
            }
        }

        return $set;
    }

    /**
     * Returns the primary key value of an entity.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ORMMappingException
     */
    private function id(EntityManagerInterface $entityManager, object $entity, ?ClassMetadata $meta = null): mixed
    {
        $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $connection = $entityManager->getConnection();

        try {
            $pk = $meta->getSingleIdentifierFieldName();
        } catch (ORMMappingException) {
            throw new MappingException(\sprintf('Composite primary keys are not supported (%s).', $entity::class));
        }

        $type = $this->getType($connection, $meta, $pk);
        if (null !== $type) {
            return $this->value($connection, $type, DoctrineHelper::getReflectionPropertyValue($meta, $pk, $entity));
        }

        /**
         * Primary key is not part of fieldMapping.
         *
         * @see https://github.com/DamienHarper/auditor-bundle/issues/40
         * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
         * We try to get it from associationMapping (will throw a MappingException if not available)
         */
        $targetEntity = DoctrineHelper::getReflectionPropertyValue($meta, $pk, $entity);

        $mapping = $meta->getAssociationMapping($pk);

        \assert(\is_string($mapping['targetEntity']));
        $meta = $entityManager->getClassMetadata($mapping['targetEntity']);
        $pk = $meta->getSingleIdentifierFieldName();

        $type = $this->getType($connection, $meta, $pk);
        \assert(\is_object($type));
        \assert(\is_object($targetEntity));

        return $this->value($connection, $type, DoctrineHelper::getReflectionPropertyValue($meta, $pk, $targetEntity));
    }

    /**
     * Type converts the input value and returns it.
     *
     * @throws Exception
     * @throws ConversionException
     */
    private function value(Connection $connection, Type $type, mixed $value): mixed
    {
        $platform = $connection->getDatabasePlatform();

        if (null === $value) {
            return null;
        }

        if ($value instanceof \UnitEnum && property_exists($value, 'value')) {
            $value = $value->value;
        }

        if ($type instanceof NeedsConversionToAuditableType) {
            return $type->convertToAuditableValue($value, $platform);
        }

        switch (DoctrineHelper::getTypeName($connection, $type)) {
            case Types::BIGINT:
            case 'uuid_binary':
            case 'uuid_binary_ordered_time':
            case 'uuid':
            case 'ulid':
                $convertedValue = (string) $value;  // @phpstan-ignore-line

                break;

            case Types::INTEGER:
            case Types::SMALLINT:
                $convertedValue = (int) $value; // @phpstan-ignore-line

                break;

            case Types::DECIMAL:
                $convertedValue = $type->convertToPHPValue($value, $platform);
                // Normalize decimal strings to avoid false positives when comparing
                // numerically equal values with different string representations
                // e.g. "60.00" (from DB) vs "60" (from a form like MoneyType)
                // @see https://github.com/DamienHarper/auditor/issues/278
                if (\is_string($convertedValue) && str_contains($convertedValue, '.')) {
                    $convertedValue = mb_rtrim(mb_rtrim($convertedValue, '0'), '.');
                }

                break;

            case Types::FLOAT:
            case Types::BOOLEAN:
                $convertedValue = $type->convertToPHPValue($value, $platform);

                break;

            case Types::BLOB:
            case Types::BINARY:
                if (\is_resource($value)) {
                    // let's replace resources with a "simple" representation: resourceType#resourceId
                    $convertedValue = get_resource_type($value).'#'.get_resource_id($value);
                } else {
                    $convertedValue = $type->convertToDatabaseValue($value, $platform);
                }

                break;

            case Types::JSON:
            case 'jsonb':
                return $value;

            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }

    /**
     * Computes a unified diff envelope:
     * [
     *   'source'  => ['id' => ..., 'class' => ..., 'label' => ..., 'table' => ...],
     *   'changes' => [
     *     'field1' => ['old' => $oldValue, 'new' => $newValue],
     *     'field2' => ['old' => null,       'new' => $newValue],  // INSERT: old is always null
     *     'jsonField' => [
     *       'nested' => ['old' => $old, 'new' => $new],           // deep diff for JSON columns
     *     ],
     *   ],
     *   'deep_diffs' => ['jsonField'],                             // only when at least one deep diff
     * ]
     *
     * Both 'old' and 'new' keys are always present for every field diff. A deep diff only holds the
     * keys that changed, so a JSON object whose own keys are 'new'/'old' gives a deep diff shaped like
     * a field diff: 'deep_diffs' lists the fields whose change is a deep diff to tell them apart.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ConversionException
     * @throws ORMMappingException
     */
    private function diff(EntityManagerInterface $entityManager, object $entity, array $changeset, ?ClassMetadata $meta = null): array
    {
        $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $connection = $entityManager->getConnection();
        $source = $this->summarize($entityManager, $entity, [], $meta);
        $changes = [];
        $deepDiffs = [];

        $resolverLocator = $this->provider->getDiffLabelResolverLocator();
        $jsonTypes = DoctrineHelper::jsonTypes($connection);

        [$ignoredColumns, $auditedColumns] = $this->columnFilter($meta->name, $entity);
        $diffLabelResolvers = $this->entityConfig($meta->name)['diff_label_resolvers'];

        foreach ($changeset as $fieldName => [$old, $new]) {
            $o = null;
            $n = null;

            // skip if $old and $new are null
            if (null === $old && null === $new) {
                continue;
            }

            $isAuditedField = !isset($ignoredColumns[$fieldName])
                && (null === $auditedColumns || isset($auditedColumns[$fieldName]));

            $type = null;
            if (
                $isAuditedField
                && !isset($meta->embeddedClasses[$fieldName])
                && $meta->hasField($fieldName)
            ) {
                $type = $this->getType($connection, $meta, $fieldName);
                \assert(\is_object($type));
                $o = $this->value($connection, $type, $old);
                $n = $this->value($connection, $type, $new);

                // Apply DiffLabel resolver if configured for this field
                if ([] !== $diffLabelResolvers && isset($diffLabelResolvers[$fieldName]) && null !== $resolverLocator) {
                    $resolverFqcn = $diffLabelResolvers[$fieldName];
                    if ($resolverLocator->has($resolverFqcn)) {
                        $resolver = $resolverLocator->get($resolverFqcn);
                        if (null !== $o) {
                            $oLabel = $resolver($o);
                            if (null !== $oLabel) {
                                $o = ['value' => $o, 'label' => $oLabel];
                            }
                        }

                        if (null !== $n) {
                            $nLabel = $resolver($n);
                            if (null !== $nLabel) {
                                $n = ['value' => $n, 'label' => $nLabel];
                            }
                        }
                    }
                }
            } elseif (
                $isAuditedField
                && $meta->hasAssociation($fieldName)
                && $meta->isSingleValuedAssociation($fieldName)
            ) {
                $o = $this->summarize($entityManager, $old);
                $n = $this->summarize($entityManager, $new);
            }

            if ($o !== $n) {
                if (
                    isset($type) && \in_array($type, $jsonTypes, true)
                    && (null === $o || \is_array($o)) && (null === $n || \is_array($n))
                ) {
                    /**
                     * @var ?array $o
                     * @var ?array $n
                     */
                    $changes[$fieldName] = $this->deepDiff($o, $n);
                    $deepDiffs[] = $fieldName;
                } else {
                    // Always include both old and new, even when null (e.g. INSERT has old=null)
                    $changes[$fieldName] = ['new' => $n, 'old' => $o];
                }
            }
        }

        $diff = [
            'source' => $source,
            'changes' => $changes,
        ];

        if ([] !== $deepDiffs) {
            $diff['deep_diffs'] = $deepDiffs;
        }

        return $diff;
    }

    /**
     * Captures the full state of an entity before removal.
     *
     * Returns a unified diff envelope where every audited field appears in 'changes'
     * with old: <current value> and new: null. This allows reconstructing what was
     * deleted from the audit log alone.
     *
     * Only called when Configuration::isFullSnapshotOnRemoveEnabled() is true.
     * Skipped for ignored (or not allowlisted) columns and embedded classes.
     *
     * $id is the identifier captured before the flush: Doctrine resets a generated identifier once the
     * DELETE statement has run, so the source takes $id and the generated identifier is left out of
     * 'changes', as in an insert. A natural identifier is still on the entity and is recorded.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ConversionException
     * @throws ORMMappingException
     */
    private function removalSnapshot(EntityManagerInterface $entityManager, object $entity, mixed $id, ?ClassMetadata $meta = null): array
    {
        $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $connection = $entityManager->getConnection();
        $source = $this->summarize($entityManager, $entity, [], $meta, $id);
        $changes = [];

        [$ignoredColumns, $auditedColumns] = $this->columnFilter($meta->name, $entity);

        foreach (array_keys($meta->fieldMappings) as $fieldName) {
            if ($meta->isIdentifier($fieldName) && !$meta->isIdentifierNatural()) {
                continue;
            }

            if (isset($ignoredColumns[$fieldName])) {
                continue;
            }

            if (null !== $auditedColumns && !isset($auditedColumns[$fieldName])) {
                continue;
            }

            if (isset($meta->embeddedClasses[$fieldName])) {
                continue;
            }

            $type = $this->getType($connection, $meta, $fieldName);
            if (null === $type) {
                continue;
            }

            $value = DoctrineHelper::getReflectionPropertyValue($meta, $fieldName, $entity);
            $changes[$fieldName] = ['new' => null, 'old' => $this->value($connection, $type, $value)];
        }

        return [
            'source' => $source,
            'changes' => $changes,
        ];
    }

    /**
     * Returns an array describing an entity.
     *
     * $knownId is the identifier captured before the flush for an entity deleted in that flush:
     * Doctrine resets the generated identifier of a deleted entity, so it can no longer be read
     * from the entity itself.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ORMMappingException
     */
    private function summarize(EntityManagerInterface $entityManager, ?object $entity = null, array $extra = [], ?ClassMetadata $meta = null, mixed $knownId = null): ?array
    {
        if (null === $entity) {
            return null;
        }

        $entityClass = $entity::class;

        if (str_contains($entityClass, '__CG__')) {
            // Legacy Doctrine __CG__ proxy: may fail to initialize when the entity row is
            // inaccessible (e.g. hidden by SoftDeleteable or hard-deleted between flushes).
            // Fall back to the identifier stored in the UoW identity map in that case.
            //
            // @see https://github.com/DamienHarper/auditor/issues/285
            try {
                $entityManager->getUnitOfWork()->initializeObject($entity);
            } catch (\Throwable) {
                $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));

                try {
                    $pkName = $meta->getSingleIdentifierFieldName();
                } catch (\Throwable) {
                    $pkName = 'id';
                }

                $identifiers = $entityManager->getUnitOfWork()->getEntityIdentifier($entity);
                $pkValue = $extra['id'] ?? ($identifiers[$pkName] ?? $knownId);
                $label = DoctrineHelper::getRealClassName($entity).(null === $pkValue ? '' : '#'.$pkValue);
                if ('id' !== $pkName) {
                    $extra['pkName'] = $pkName;
                }

                return [
                    $pkName => $pkValue,
                    'class' => $meta->name,
                    'label' => $label,
                    'table' => $meta->getTableName(),
                ] + $extra;
            }

            $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        } else {
            // Plain entity or PHP 8.4+ native lazy object: already initialized by UoW change
            // detection before summarize() is ever called — skip the try/catch overhead entirely.
            $meta ??= $entityManager->getClassMetadata($entityClass);
        }

        if ($meta->isIdentifierComposite) {
            // An entity with a composite key cannot be audited itself (see id()), but it shows up as
            // the target of an association audited on the other side. Its key is flattened into one
            // string for the summary, in the same format as 1.x so migrated entries stay consistent.
            // $knownId comes first: the unit of work no longer knows an entity deleted in this flush.
            $pkValue = $extra['id'] ?? $knownId ?? implode('-', $entityManager->getUnitOfWork()->getEntityIdentifier($entity));
            $pkName = implode('-', $meta->getIdentifierFieldNames());
        } else {
            // Pass $meta to avoid a redundant getClassMetadata() call inside id()
            $pkValue = $extra['id'] ?? $knownId ?? $this->id($entityManager, $entity, $meta);
            $pkName = $meta->getSingleIdentifierFieldName();
        }

        // the "entity_label_resolver" comes first, then the string representation of the entity, then Class#id
        $label = $this->resolveEntityLabel($entity);
        if (null === $label && (self::$hasToStringCache[$entityClass] ??= method_exists($entity, '__toString'))) {
            try {
                $label = (string) $entity;
            } catch (\Throwable) {
                $label = null;
            }
        }

        $label ??= DoctrineHelper::getRealClassName($entity).(null === $pkValue ? '' : '#'.$pkValue);

        if ('id' !== $pkName) {
            $extra['pkName'] = $pkName;
        }

        return [
            $pkName => $pkValue,
            'class' => $meta->name,
            'label' => $label,
            'table' => $meta->getTableName(),
        ] + $extra;
    }

    /**
     * Label of an entity given by the "entity_label_resolver", null without resolver or when it has no label for it.
     */
    private function resolveEntityLabel(object $entity): ?string
    {
        $configuration = $this->provider->getConfiguration();
        if (!$configuration instanceof Configuration) {
            return null;
        }

        $resolver = $configuration->getEntityLabelResolver();
        if (null === $resolver) {
            return null;
        }

        $label = $resolver($entity);

        return \is_string($label) || $label instanceof \Stringable ? (string) $label : null;
    }

    /**
     * Blames an audit operation.
     *
     * @param null|UserInterface $user user to blame instead of the one returned by the user provider
     *
     * @return array{client_ip: null|string, user_firewall: null|string, user_fqdn: null|string, user_id: null|string, username: null|string}
     */
    private function blame(?UserInterface $user = null): array
    {
        $user_id = null;
        $username = null;
        $client_ip = null;
        $user_fqdn = null;
        $user_firewall = null;

        $securityProvider = $this->provider->getAuditor()->getConfiguration()->getSecurityProvider();
        if (null !== $securityProvider) {
            [$client_ip, $user_firewall] = $securityProvider();
        }

        if (!$user instanceof UserInterface) {
            $userProvider = $this->provider->getAuditor()->getConfiguration()->getUserProvider();
            $user = null === $userProvider ? null : $userProvider();
        }

        if ($user instanceof UserInterface) {
            $user_id = $user->identifier;
            $username = $user->username;
            $user_fqdn = DoctrineHelper::getRealClassName($user);
        }

        return [
            'client_ip' => $client_ip,
            'user_firewall' => $user_firewall,
            'user_fqdn' => $user_fqdn,
            'user_id' => $user_id,
            'username' => $username,
        ];
    }

    /**
     * Returns a JSON-encoded string of extra data to attach to every audit entry,
     * or null when no extra_data provider is configured or the provider returns null.
     *
     * @see https://github.com/DamienHarper/auditor-bundle/issues/594
     */
    private function extraData(): ?string
    {
        $extraDataProvider = $this->provider->getAuditor()->getConfiguration()->getExtraDataProvider();
        if (null === $extraDataProvider) {
            return null;
        }

        $data = $extraDataProvider();

        return null === $data ? null : json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function deepDiff(?array $old, ?array $new): array
    {
        $diff = [];

        // Check for differences in $old
        if (null !== $old && null !== $new) {
            foreach ($old as $key => $value) {
                if (!\array_key_exists($key, $new)) {
                    // $key does not exist in $new, it's been removed
                    $diff[$key] = \is_array($value) ? $this->formatArray($value, 'old') : ['old' => $value];
                } elseif (\is_array($value) && \is_array($new[$key])) {
                    // both values are arrays, compare them recursively
                    $recursiveDiff = $this->deepDiff($value, $new[$key]);
                    if ([] !== $recursiveDiff) {
                        $diff[$key] = $recursiveDiff;
                    }
                } elseif ($new[$key] !== $value) {
                    // values are different
                    $diff[$key] = ['new' => $new[$key], 'old' => $value];
                }
            }
        }

        // Check for new elements in $new
        if (null !== $new) {
            foreach ($new as $key => $value) {
                if (!\array_key_exists($key, $old ?? [])) {
                    // $key does not exist in $old, it's been added
                    $diff[$key] = \is_array($value) ? $this->formatArray($value, 'new') : ['new' => $value];
                }
            }
        }

        return $diff;
    }

    private function formatArray(array $array, string $prefix): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (\is_array($value)) {
                $result[$key] = $this->formatArray($value, $prefix);
            } else {
                $result[$key][$prefix] = $value;
            }
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    private function getType(Connection $connection, ClassMetadata $meta, string $fieldName): ?Type
    {
        $mapping = $meta->fieldMappings[$fieldName] ?? null;
        if (null === $mapping) {
            return null;
        }

        $type = $mapping instanceof FieldMapping ? $mapping->type : $mapping['type'];

        return DoctrineHelper::getType($connection, $type);
    }
}
