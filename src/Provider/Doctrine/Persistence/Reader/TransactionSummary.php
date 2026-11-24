<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader;

use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\QueryTransactionsTest;

/**
 * A transaction read by Query::transactions(): what a page of actions is made of.
 *
 * An entry written without transaction id is a transaction of its own: its id and audit table identify it.
 *
 * @see QueryTransactionsTest
 */
final readonly class TransactionSummary
{
    /**
     * @param null|string        $transactionId the transaction id (transaction_hash in a table not migrated to schema v2)
     * @param null|int           $entryId       id of the entry, when it has no transaction id
     * @param null|string        $table         audit table of the entry, when it has no transaction id
     * @param \DateTimeImmutable $createdAt     date of the most recent entry of the transaction
     * @param int                $entries       number of entries of the transaction the queries read
     */
    public function __construct(
        public ?string $transactionId,
        public ?int $entryId,
        public ?string $table,
        public \DateTimeImmutable $createdAt,
        public int $entries,
    ) {}

    /**
     * Identifies the transaction among those of several audit tables.
     */
    public function key(): string
    {
        return $this->transactionId ?? $this->table.'#'.$this->entryId;
    }
}
