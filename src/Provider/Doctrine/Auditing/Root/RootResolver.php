<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Root;

use DH\Auditor\Attribute\AuditRoot;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Tests\Provider\Doctrine\Auditing\Root\RootIndexTest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;

/**
 * Finds the audit roots of an entity by following its root paths.
 *
 * - When the flush changes the first association of a path, both the old and the new value are followed: the entry
 *   belongs to the history of the root it leaves and of the root it joins.
 * - On the inverse side of a one-to-one association, the in-memory value may not be set yet when the owning side was
 *   just assigned: the owner is then looked up among the entities scheduled for insertion or update.
 * - A path whose value is null is skipped.
 *
 * @see RootIndexTest
 *
 * @internal
 */
final class RootResolver
{
    /**
     * @var array<string, list<string>> validated segments by path
     */
    private array $segments = [];

    private readonly ObjectIdentifier $identifier;

    public function __construct(private readonly DoctrineProvider $provider, ?ObjectIdentifier $identifier = null)
    {
        $this->identifier = $identifier ?? new ObjectIdentifier($provider);
    }

    /**
     * Identifier of a root, in the format of the object_id column; null while the flush has not generated it.
     */
    public function identify(EntityManagerInterface $entityManager, object $root): ?string
    {
        return $this->identifier->identify($entityManager, $root);
    }

    /**
     * @param array<string, mixed> $changeset changeset of the entity in the current flush, if any
     *
     * @return list<RootReference>
     */
    public function resolve(EntityManagerInterface $entityManager, object $entity, array $changeset = []): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->provider->getConfiguration();
        $class = DoctrineHelper::getRealClassName($entity);

        $references = [];
        foreach ($configuration->getRoots($class) as $path) {
            foreach ($this->follow($entityManager, $entity, $class, $path, $changeset) as $root) {
                $reference = $this->reference($entityManager, $path, $root);
                $references[$reference->key()] = $reference;
            }
        }

        foreach ($configuration->resolveRoots($entity) as $path => $root) {
            $reference = $this->reference($entityManager, $path, $root);
            $references[$reference->key()] = $reference;
        }

        return array_values($references);
    }

    /**
     * @param array<string, mixed> $changeset
     *
     * @return list<object>
     */
    private function follow(EntityManagerInterface $entityManager, object $entity, string $class, string $path, array $changeset): array
    {
        $segments = $this->segments[$path] ??= new AuditRoot($path)->getSegments();
        $first = array_shift($segments);
        $meta = $entityManager->getClassMetadata($class);
        $this->checkAssociation($meta, $first, $class, $path);

        $starts = [];
        if (\array_key_exists($first, $changeset) && \is_array($changeset[$first])) {
            // [old, new]: the entry belongs to the root it leaves and to the root it joins
            foreach ($changeset[$first] as $value) {
                if (\is_object($value)) {
                    $starts[] = $value;
                }
            }
        } else {
            $value = $this->value($entityManager, $meta, $entity, $first);
            $value ??= $this->findOwner($entityManager, $meta, $entity, $first);

            if (null !== $value) {
                $starts[] = $value;
            }
        }

        $roots = [];
        foreach ($starts as $start) {
            $current = $start;
            foreach ($segments as $segment) {
                $currentClass = DoctrineHelper::getRealClassName($current);
                $currentMeta = $entityManager->getClassMetadata($currentClass);
                $this->checkAssociation($currentMeta, $segment, $class, $path);
                $current = $this->value($entityManager, $currentMeta, $current, $segment);
                if (null === $current) {
                    continue 2;
                }
            }

            $roots[spl_object_id($current)] = $current;
        }

        return array_values($roots);
    }

    private function checkAssociation(ClassMetadata $meta, string $field, string $class, string $path): void
    {
        if (!$meta->hasAssociation($field)) {
            throw new InvalidArgumentException(\sprintf('The audit root path "%s" of "%s" is invalid: "%s" is not an association of "%s".', $path, $class, $field, $meta->getName()));
        }

        if (!$meta->isSingleValuedAssociation($field)) {
            throw new InvalidArgumentException(\sprintf('The audit root path "%s" of "%s" is invalid: "%s" of "%s" is a collection, a root path only follows single-valued associations.', $path, $class, $field, $meta->getName()));
        }
    }

    private function value(EntityManagerInterface $entityManager, ClassMetadata $meta, object $entity, string $field): ?object
    {
        try {
            $entityManager->getUnitOfWork()->initializeObject($entity);
        } catch (EntityNotFoundException) {
            // an uninitialized proxy whose row is gone: no root to follow
            $this->provider->getLogger()->warning('auditor: the audit root of {class} is not indexed, its row is gone.', ['class' => $meta->getName(), 'field' => $field]);

            return null;
        }

        $value = $meta->getFieldValue($entity, $field);

        return \is_object($value) ? $value : null;
    }

    /**
     * Looks for the owner of an inverse one-to-one association among the entities of the flush.
     */
    private function findOwner(EntityManagerInterface $entityManager, ClassMetadata $meta, object $entity, string $field): ?object
    {
        $mapping = $meta->getAssociationMapping($field);
        if (!$mapping instanceof InverseSideMapping || !$mapping->isOneToOne()) {
            return null;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $targetMeta = $entityManager->getClassMetadata($mapping->targetEntity);
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $candidate) {
            if (!$candidate instanceof $targetMeta->name) {
                continue;
            }

            if ($this->value($entityManager, $targetMeta, $candidate, $mapping->mappedBy) === $entity) {
                return $candidate;
            }
        }

        return null;
    }

    private function reference(EntityManagerInterface $entityManager, string $path, object $root): RootReference
    {
        $meta = $entityManager->getClassMetadata(DoctrineHelper::getRealClassName($root));

        // captured now: Doctrine resets the generated identifier of an entity deleted by the flush
        return new RootReference($path, $meta->rootEntityName, $root, $this->identify($entityManager, $root));
    }
}
