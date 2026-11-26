<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Attribute;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\AuditRoot;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Attribute\Ignore;
use DH\Auditor\Attribute\Mask;
use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Attribute\AttributeLoaderTest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\Persistence\Mapping\ClassMetadata;

/**
 * @see AttributeLoaderTest
 */
final readonly class AttributeLoader
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function load(): array
    {
        $configuration = [];

        $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();
        foreach ($metadatas as $metadata) {
            $config = $this->getEntityConfiguration($metadata);
            if (null !== $config) {
                $configuration[$metadata->getName()] = $config;
            }
        }

        return $configuration;
    }

    private function getEntityConfiguration(ClassMetadata $metadata): ?array
    {
        $entityAttribute = null;
        $auditableAttribute = null;
        $securityAttribute = null;
        $reflection = $metadata->getReflectionClass();

        // Check that we have an Entity attribute
        $attributes = $reflection->getAttributes(Entity::class);
        if ([] !== $attributes) {
            $entityAttribute = $attributes[0]->newInstance();
        }

        if (!$entityAttribute instanceof Entity) {
            return null;
        }

        // Check that we have an Auditable attribute (accepts both core and legacy Doctrine namespace)
        $attributes = $reflection->getAttributes(Auditable::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ([] !== $attributes) {
            try {
                $auditableAttribute = $attributes[0]->newInstance();
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(\sprintf('Invalid #[Auditable] attribute on "%s": %s', $metadata->getName(), $exception->getMessage()), 0, $exception);
            }
        }

        if (!$auditableAttribute instanceof Auditable) {
            return null;
        }

        // Check that we have a Security attribute (accepts both core and legacy Doctrine namespace)
        $attributes = $reflection->getAttributes(Security::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ([] !== $attributes) {
            $securityAttribute = $attributes[0]->newInstance();
        }

        $roles = $securityAttribute instanceof Security ? [Security::VIEW_SCOPE => $securityAttribute->view] : null;

        // Are there any Ignore attributes?
        $ignoredColumns = $this->getAllProperties($reflection);

        // Are there any DiffLabel attributes?
        $diffLabelResolvers = $this->getDiffLabelResolvers($reflection);

        // Are there any Mask attributes?
        $masks = $this->getMasks($reflection);

        // Are there any AuditRoot attributes? (repeatable, inherited from parent classes)
        $roots = $this->getRoots($reflection);

        return [
            'ignored_columns' => $ignoredColumns,
            'roots' => $roots,
            'enabled' => $auditableAttribute->enabled,
            'roles' => $roles,
            'diff_label_resolvers' => $diffLabelResolvers,
            'masks' => $masks,
            'max_age' => $auditableAttribute->maxAge,
            'max_entries' => $auditableAttribute->maxEntries,
        ];
    }

    /**
     * @return list<string>
     */
    private function getRoots(\ReflectionClass $reflection): array
    {
        $roots = [];
        foreach ($reflection->getAttributes(AuditRoot::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            try {
                $roots[] = $attribute->newInstance()->path;
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidArgumentException(\sprintf('Invalid #[AuditRoot] attribute on "%s": %s', $reflection->getName(), $exception->getMessage()), 0, $exception);
            }
        }

        if (false !== $reflection->getParentClass()) {
            $roots = [...$this->getRoots($reflection->getParentClass()), ...$roots];
        }

        return array_values(array_unique($roots));
    }

    /**
     * Collects the fields excluded by #[Ignore], on the properties or on the class (with a field).
     *
     * @return list<string>
     */
    private function getAllProperties(\ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getAttributes(Ignore::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $properties[] = $this->classLevelField($attribute->newInstance()->field, 'Ignore', $reflection);
        }

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Ignore::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ([] !== $attributes) {
                $this->assertNoPropertyLevelField($attributes[0]->newInstance()->field, 'Ignore', $property);
                $properties[] = $property->getName();
            }
        }

        if (false !== $reflection->getParentClass()) {
            return array_values(array_unique(array_merge($this->getAllProperties($reflection->getParentClass()), $properties)));
        }

        return array_values(array_unique($properties));
    }

    /**
     * Collects DiffLabel resolvers defined on entity properties or on the class (with a field).
     *
     * @return array<string, string> Map of property name => resolver FQCN
     */
    private function getDiffLabelResolvers(\ReflectionClass $reflection): array
    {
        $resolvers = [];

        foreach ($reflection->getAttributes(DiffLabel::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $diffLabel = $attribute->newInstance();
            $resolvers[$this->classLevelField($diffLabel->field, 'DiffLabel', $reflection)] = $diffLabel->resolver;
        }

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DiffLabel::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ([] !== $attributes) {
                $diffLabel = $attributes[0]->newInstance();
                $this->assertNoPropertyLevelField($diffLabel->field, 'DiffLabel', $property);
                $resolvers[$property->getName()] = $diffLabel->resolver;
            }
        }

        if (false !== $reflection->getParentClass()) {
            return array_merge($this->getDiffLabelResolvers($reflection->getParentClass()), $resolvers);
        }

        return $resolvers;
    }

    /**
     * Collects the #[Mask] strategies defined on entity properties or on the class (with a field).
     *
     * @return array<string, string> Map of property name => strategy
     */
    private function getMasks(\ReflectionClass $reflection): array
    {
        $masks = [];

        foreach ($reflection->getAttributes(Mask::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $mask = $attribute->newInstance();
            $masks[$this->classLevelField($mask->field, 'Mask', $reflection)] = $mask->strategy;
        }

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Mask::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ([] !== $attributes) {
                $mask = $attributes[0]->newInstance();
                $this->assertNoPropertyLevelField($mask->field, 'Mask', $property);
                $masks[$property->getName()] = $mask->strategy;
            }
        }

        if (false !== $reflection->getParentClass()) {
            return array_merge($this->getMasks($reflection->getParentClass()), $masks);
        }

        return $masks;
    }

    private function classLevelField(?string $field, string $attribute, \ReflectionClass $reflection): string
    {
        if (null === $field) {
            throw new InvalidArgumentException(\sprintf('Invalid #[%s] attribute on "%s": a class-level #[%s] needs a "field" argument.', $attribute, $reflection->getName(), $attribute));
        }

        return $field;
    }

    private function assertNoPropertyLevelField(?string $field, string $attribute, \ReflectionProperty $property): void
    {
        if (null !== $field) {
            throw new InvalidArgumentException(\sprintf('Invalid #[%s] attribute on "%s::$%s": leave "field" out on a property, the property is the field.', $attribute, $property->getDeclaringClass()->getName(), $property->getName()));
        }
    }
}
