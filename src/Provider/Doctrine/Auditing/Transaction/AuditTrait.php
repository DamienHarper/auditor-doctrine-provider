<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Transaction;

use DH\Auditor\Exception\MappingException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Transaction\NeedsConversionToAuditableType;
use DH\Auditor\User\UserInterface;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\MappingException as ORMMappingException;

trait AuditTrait
{
    private static array $typeNameCache = [];

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
        $platform = $entityManager->getConnection()->getDatabasePlatform();

        try {
            $pk = $meta->getSingleIdentifierFieldName();
        } catch (ORMMappingException) {
            throw new MappingException(\sprintf('Composite primary keys are not supported (%s).', $entity::class));
        }

        $type = $this->getType($meta, $pk);
        if (null !== $type) {
            return $this->value($platform, $type, DoctrineHelper::getReflectionPropertyValue($meta, $pk, $entity));
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

        $type = $this->getType($meta, $pk);
        \assert(\is_object($type));
        \assert(\is_object($targetEntity));

        return $this->value($platform, $type, DoctrineHelper::getReflectionPropertyValue($meta, $pk, $targetEntity));
    }

    /**
     * Type converts the input value and returns it.
     *
     * @throws Exception
     * @throws ConversionException
     */
    private function value(AbstractPlatform $platform, Type $type, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \UnitEnum && property_exists($value, 'value')) {
            $value = $value->value;
        }

        if ($type instanceof NeedsConversionToAuditableType) {
            return $type->convertToAuditableValue($value, $platform);
        }

        switch ($this->getTypeName($type)) {
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
     * ]
     *
     * Both 'old' and 'new' keys are always present for every changed field.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ConversionException
     * @throws ORMMappingException
     */
    private function diff(EntityManagerInterface $entityManager, object $entity, array $changeset, ?ClassMetadata $meta = null): array
    {
        $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $platform = $entityManager->getConnection()->getDatabasePlatform();
        $source = $this->summarize($entityManager, $entity, [], $meta);
        $changes = [];

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $globalIgnoredColumns = $configuration->getIgnoredColumns();
        $entityIgnoredColumns = $configuration->getEntities()[$meta->name]['ignored_columns'] ?? [];
        $diffLabelResolvers = $configuration->getEntities()[$meta->name]['diff_label_resolvers'] ?? [];
        $resolverLocator = $this->provider->getDiffLabelResolverLocator();
        $jsonTypes = DoctrineHelper::jsonTypes();

        foreach ($changeset as $fieldName => [$old, $new]) {
            $o = null;
            $n = null;

            // skip if $old and $new are null
            if (null === $old && null === $new) {
                continue;
            }

            $isAuditedField = !\in_array($fieldName, $globalIgnoredColumns, true)
                && !\in_array($fieldName, $entityIgnoredColumns, true);

            $type = null;
            if (
                $isAuditedField
                && !isset($meta->embeddedClasses[$fieldName])
                && $meta->hasField($fieldName)
            ) {
                $type = $this->getType($meta, $fieldName);
                \assert(\is_object($type));
                $o = $this->value($platform, $type, $old);
                $n = $this->value($platform, $type, $new);

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
                } else {
                    // Always include both old and new, even when null (e.g. INSERT has old=null)
                    $changes[$fieldName] = ['old' => $o, 'new' => $n];
                }
            }
        }

        return [
            'source' => $source,
            'changes' => $changes,
        ];
    }

    /**
     * Captures the full state of an entity before removal.
     *
     * Returns a unified diff envelope where every audited field appears in 'changes'
     * with old: <current value> and new: null. This allows reconstructing what was
     * deleted from the audit log alone.
     *
     * Only called when Configuration::isFullSnapshotOnRemoveEnabled() is true.
     * Skipped for ignored columns and embedded classes.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ConversionException
     * @throws ORMMappingException
     */
    private function snapshot(EntityManagerInterface $entityManager, object $entity, ?ClassMetadata $meta = null): array
    {
        $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $platform = $entityManager->getConnection()->getDatabasePlatform();
        $source = $this->summarize($entityManager, $entity, [], $meta);
        $changes = [];

        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $globalIgnoredColumns = $configuration->getIgnoredColumns();
        $entityIgnoredColumns = $configuration->getEntities()[$meta->name]['ignored_columns'] ?? [];

        foreach (array_keys($meta->fieldMappings) as $fieldName) {
            if (\in_array($fieldName, $globalIgnoredColumns, true)) {
                continue;
            }

            if (\in_array($fieldName, $entityIgnoredColumns, true)) {
                continue;
            }

            if (isset($meta->embeddedClasses[$fieldName])) {
                continue;
            }

            $type = $this->getType($meta, $fieldName);
            if (null === $type) {
                continue;
            }

            $value = DoctrineHelper::getReflectionPropertyValue($meta, $fieldName, $entity);
            $changes[$fieldName] = ['old' => $this->value($platform, $type, $value), 'new' => null];
        }

        return [
            'source' => $source,
            'changes' => $changes,
        ];
    }

    /**
     * Returns an array describing an entity.
     *
     * @throws MappingException
     * @throws Exception
     * @throws ORMMappingException
     */
    private function summarize(EntityManagerInterface $entityManager, ?object $entity = null, array $extra = [], ?ClassMetadata $meta = null): ?array
    {
        if (null === $entity) {
            return null;
        }

        try {
            $entityManager->getUnitOfWork()->initializeObject($entity); // ensure that proxies are initialized
        } catch (\Throwable) {
            /**
             * Proxy initialization failed — the entity row is inaccessible (e.g. hidden by a
             * Doctrine filter such as SoftDeleteable, or hard-deleted between two flushes).
             * Fall back to the identifier stored in the UoW identity map, which is available
             * without accessing any property on the (possibly uninitialized) proxy object.
             *
             * @see https://github.com/DamienHarper/auditor/issues/285
             */
            $meta ??= $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($entity));

            try {
                $pkName = $meta->getSingleIdentifierFieldName();
            } catch (\Throwable) {
                $pkName = 'id';
            }

            $identifiers = $entityManager->getUnitOfWork()->getEntityIdentifier($entity);
            $pkValue = $extra['id'] ?? ($identifiers[$pkName] ?? null);
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

        $pkValue = $extra['id'] ?? $this->id($entityManager, $entity);
        $pkName = $meta->getSingleIdentifierFieldName();

        if (method_exists($entity, '__toString')) {
            try {
                $label = (string) $entity;
            } catch (\Throwable) {
                $label = DoctrineHelper::getRealClassName($entity).(null === $pkValue ? '' : '#'.$pkValue);
            }
        } else {
            $label = DoctrineHelper::getRealClassName($entity).(null === $pkValue ? '' : '#'.$pkValue);
        }

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
     * Blames an audit operation.
     *
     * @return array{client_ip: null|string, user_firewall: null|string, user_fqdn: null|string, user_id: null|string, username: null|string}
     */
    private function blame(): array
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

        $userProvider = $this->provider->getAuditor()->getConfiguration()->getUserProvider();
        $user = null === $userProvider ? null : $userProvider();
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
                    $diff[$key] = ['old' => $value, 'new' => $new[$key]];
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

    private function getTypeName(Type $type): false|string
    {
        return self::$typeNameCache[$type::class]
            ??= array_search($type::class, Type::getTypesMap(), true);
    }

    /**
     * @throws Exception
     */
    private function getType(ClassMetadata $meta, string $fieldName): ?Type
    {
        $mapping = $meta->fieldMappings[$fieldName] ?? null;
        if (null === $mapping) {
            return null;
        }

        $type = $mapping instanceof FieldMapping ? $mapping->type : $mapping['type'];

        return Type::getType($type);
    }
}
