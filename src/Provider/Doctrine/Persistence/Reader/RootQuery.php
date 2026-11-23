<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\CursorFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\FilterInterface;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\RootQueryTest;

/**
 * The history of an audit root: the entries of the root itself and those indexed under it (see #[AuditRoot]),
 * read from every audit table concerned and merged, most recent first.
 *
 * Entries are ordered by created_at, transaction id, entity and id, all descending, and paginated with opaque
 * cursors (keyset pagination): a page never shifts when entries are written in the meantime.
 *
 * @see RootQueryTest
 */
final readonly class RootQuery
{
    /**
     * @param list<array{entity: string, query: Query}> $sources one query per audit table, with the FQCN of its entity
     */
    public function __construct(
        private string $rootClass,
        private string $rootId,
        private array $sources,
    ) {}

    public function getRootClass(): string
    {
        return $this->rootClass;
    }

    public function getRootId(): string
    {
        return $this->rootId;
    }

    /**
     * @return list<array{entity: string, query: Query}>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Adds a filter to the query of every audit table.
     */
    public function addFilter(FilterInterface $filter): self
    {
        foreach ($this->sources as $source) {
            $source['query']->addFilter($filter);
        }

        return $this;
    }

    /**
     * Returns a page of the history.
     *
     * By default, the page is extended past $limit until the transaction of its last entry is complete, so that an
     * action is never split between two pages: pass the grouper (or the key callable) used to display the page to
     * keep its groups whole, or false to cut the page at $limit exactly.
     *
     * @param null|string                                          $after    cursor of the last entry of the previous page (RootPage::$lastCursor): reads older entries
     * @param null|string                                          $since    cursor of an entry read earlier (RootPage::$firstCursor): only reads newer entries
     * @param null|(callable(Entry): ?string)|false|HistoryGrouper $groupKey
     */
    public function fetch(int $limit = Reader::PAGE_SIZE, ?string $after = null, ?string $since = null, callable|false|HistoryGrouper|null $groupKey = null): RootPage
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The page size must be at least 1.');
        }

        $keyOf = match (true) {
            false === $groupKey => null,
            null === $groupKey => HistoryGrouper::transactionKey(...),
            $groupKey instanceof HistoryGrouper => $groupKey->keyOf(...),
            default => static fn (Entry $entry): string => $groupKey($entry) ?? HistoryGrouper::transactionKey($entry),
        };

        $entries = [];
        $firstCursor = null;
        $lastCursor = null;
        $lastKey = null;
        $hasMore = false;

        // one more than a batch: the extra entry tells whether more follow
        foreach ($this->stream($after, $since, $limit + 1) as [$entry, $cursor]) {
            if (\count($entries) >= $limit && (null === $keyOf || $keyOf($entry) !== $lastKey)) {
                $hasMore = true;

                break;
            }

            $entries[] = $entry;
            $firstCursor ??= $cursor;
            $lastCursor = $cursor;
            $lastKey = null === $keyOf ? null : $keyOf($entry);
        }

        return new RootPage($entries, $hasMore, $firstCursor, $lastCursor);
    }

    /**
     * Yields every entry of the history, most recent first, reading each audit table by batches.
     *
     * @return \Generator<string, Entry> entries keyed by cursor
     */
    public function iterate(?string $after = null, ?string $since = null, int $batchSize = 200): \Generator
    {
        foreach ($this->stream($after, $since, max(1, $batchSize)) as [$entry, $cursor]) {
            yield $cursor => $entry;
        }
    }

    /**
     * Number of entries of the history.
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->sources as $source) {
            $count += $source['query']->count();
        }

        return $count;
    }

    /**
     * Merges the streams of the audit tables.
     *
     * @return \Generator<int, array{Entry, string}>
     */
    private function stream(?string $after, ?string $since, int $batchSize): \Generator
    {
        $afterPosition = null === $after ? null : $this->decode($after);
        $sincePosition = null === $since ? null : $this->decode($since);

        /** @var list<\Generator<int, array{string, Entry, string}>> $streams */
        $streams = [];
        foreach ($this->sources as $source) {
            $stream = $this->sourceStream($source['entity'], $source['query'], $afterPosition, $sincePosition, $batchSize);
            if ($stream->valid()) {
                $streams[] = $stream;
            }
        }

        while ([] !== $streams) {
            // the stream whose current entry has the greatest sort key
            $best = null;
            foreach ($streams as $index => $stream) {
                if (null === $best || strcmp($stream->current()[0], $streams[$best]->current()[0]) > 0) {
                    $best = $index;
                }
            }

            [, $entry, $cursor] = $streams[$best]->current();

            yield [$entry, $cursor];

            $streams[$best]->next();
            if (!$streams[$best]->valid()) {
                unset($streams[$best]);
            }
        }
    }

    /**
     * Reads the entries of one audit table by batches, with a keyset cursor between batches.
     *
     * @param null|array{string, string, string, int} $after
     * @param null|array{string, string, string, int} $since
     *
     * @return \Generator<int, array{string, Entry, string}> sort key, entry and cursor
     */
    private function sourceStream(string $entity, Query $base, ?array $after, ?array $since, int $batchSize): \Generator
    {
        $position = $after;
        $tie = null === $after ? null : $this->tie($entity, $after[2], true);

        do {
            $query = clone $base;
            $query
                ->resetOrderBy()
                ->addOrderBy(Query::CREATED_AT, 'DESC')
                ->addOrderBy(Query::TRANSACTION_ID, 'DESC')
                ->addOrderBy(Query::ID, 'DESC')
                ->limit($batchSize)
            ;

            if (null !== $position && null !== $tie) {
                $query->addFilter(new CursorFilter($position[0], $position[1], $tie, $position[3]));
            }

            if (null !== $since) {
                $query->addFilter(new CursorFilter($since[0], $since[1], $this->tie($entity, $since[2], false), $since[3], false));
            }

            $read = 0;
            foreach ($query->iterate() as $row) {
                ++$read;
                $createdAt = $row['created_at'];
                \assert(\is_string($createdAt));
                $transactionId = \is_scalar($row['transaction_id'] ?? null) ? (string) $row['transaction_id'] : '';
                $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

                $row['created_at'] = new \DateTimeImmutable($createdAt, $base->getTimezone());
                $row['entity'] = $entity;
                $entry = Entry::fromArray($row);

                $position = [$createdAt, $transactionId, $entity, $id];
                $tie = CursorFilter::TIE_ID;

                yield [$this->sortKey($createdAt, $transactionId, $entity, $id), $entry, $this->encode($position)];
            }
        } while ($read === $batchSize);
    }

    /**
     * How the entries of the cursor's transaction compare to the cursor, in an audit table of $entity.
     */
    private function tie(string $entity, string $cursorEntity, bool $before): string
    {
        $comparison = strcmp($entity, $cursorEntity);

        return match (true) {
            0 === $comparison => CursorFilter::TIE_ID,
            $before === ($comparison < 0) => CursorFilter::TIE_ALL,
            default => CursorFilter::TIE_NONE,
        };
    }

    /**
     * Sort key of an entry: the raw created_at is normalized to the microsecond, so that audit tables stored on
     * different connections compare too.
     */
    private function sortKey(string $createdAt, string $transactionId, string $entity, int $id): string
    {
        try {
            $date = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Exception) {
            $date = $createdAt;
        }

        return \sprintf('%s %s %s %020d', $date, $transactionId, $entity, $id);
    }

    /**
     * @param array{string, string, string, int} $position
     */
    private function encode(array $position): string
    {
        return mb_rtrim(strtr(base64_encode(json_encode($position, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{string, string, string, int}
     */
    private function decode(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        try {
            $position = false === $json ? null : json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $position = null;
        }

        if (
            !\is_array($position)
            || 4 !== \count($position)
            || !\is_string($position[0] ?? null)
            || !\is_string($position[1] ?? null)
            || !\is_string($position[2] ?? null)
            || !\is_int($position[3] ?? null)
        ) {
            throw new InvalidArgumentException(\sprintf('Invalid history cursor "%s".', $cursor));
        }

        return [$position[0], $position[1], $position[2], $position[3]];
    }
}
