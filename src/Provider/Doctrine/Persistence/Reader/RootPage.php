<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;

/**
 * A page of the history of an audit root (see RootQuery::fetch()).
 */
final readonly class RootPage
{
    /**
     * @param list<Entry> $entries     entries of the page, most recent first
     * @param bool        $hasMore     whether older entries follow
     * @param null|string $firstCursor position of the first (most recent) entry, to read newer entries later
     * @param null|string $lastCursor  position of the last (oldest) entry, to read the next page
     */
    public function __construct(
        public array $entries,
        public bool $hasMore,
        public ?string $firstCursor,
        public ?string $lastCursor,
    ) {}

    /**
     * Groups the entries of the page into history items (one per transaction by default).
     *
     * @return list<HistoryItem>
     */
    public function group(?HistoryGrouper $grouper = null): array
    {
        return ($grouper ?? new HistoryGrouper())->group($this->entries);
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }
}
