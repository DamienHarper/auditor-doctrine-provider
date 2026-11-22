<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Auditing\Snapshot;

/**
 * Outcome of Snapshotter::snapshot().
 */
final readonly class SnapshotResult
{
    /**
     * @param int $read        entities read
     * @param int $snapshotted entities snapshotted (or that would be, in a dry run)
     */
    public function __construct(
        public int $read,
        public int $snapshotted,
    ) {}

    /**
     * Entities left out: they already have an audit history, or their class is a subclass that is not audited.
     */
    public function skipped(): int
    {
        return $this->read - $this->snapshotted;
    }
}
