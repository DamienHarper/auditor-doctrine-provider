<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

interface QueryInterface
{
    /**
     * Yields raw DB rows as associative arrays, one at a time, without loading the full
     * result set into memory. Suitable for streaming large exports.
     *
     * The 'created_at' field is a raw string — callers must convert it before calling Entry::fromArray().
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(): \Generator;
}
