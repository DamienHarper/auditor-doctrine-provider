<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing;

/**
 * Decides at runtime, for a given entity object, which of its properties are not audited.
 *
 * The returned properties are ignored on top of the static configuration (global and per-entity
 * "ignored_columns", #[Ignore] attributes, "audited_columns"): a resolver can only ignore more properties,
 * never audit a property that the configuration ignores.
 *
 * Any callable with the same signature can be used instead of a class implementing this interface.
 */
interface IgnoredColumnsResolverInterface
{
    /**
     * @param object $entity the audited entity, as it is when the flush is processed
     *
     * @return list<string> names of the mapped PHP properties of $entity that must not be audited
     */
    public function __invoke(object $entity): array;
}
