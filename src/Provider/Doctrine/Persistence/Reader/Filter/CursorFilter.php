<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter\CursorFilterTest;

/**
 * Keyset pagination of a history: keeps the entries before (older than) or after (newer than) a position, in the
 * (created_at, transaction_id, entity, id) order of RootQuery.
 *
 * The entity of the position is compared by RootQuery, as the audit table of a query only holds one entity: the
 * $tie argument tells what to do with the entries of the position's transaction.
 *
 * @internal
 *
 * @see CursorFilterTest
 */
final readonly class CursorFilter implements FilterInterface
{
    /**
     * Keeps every entry of the position's transaction.
     */
    public const string TIE_ALL = 'all';

    /**
     * Keeps the entries of the position's transaction whose id is before (or after) the position's.
     */
    public const string TIE_ID = 'id';

    /**
     * Keeps none of the entries of the position's transaction.
     */
    public const string TIE_NONE = 'none';

    /**
     * @param string $createdAt raw created_at value, as the database returns it
     */
    public function __construct(
        private string $createdAt,
        private string $transactionId,
        private string $tie,
        private int $id = 0,
        private bool $before = true,
    ) {
        if (!\in_array($tie, [self::TIE_ALL, self::TIE_ID, self::TIE_NONE], true)) {
            throw new InvalidArgumentException(\sprintf('Invalid cursor tie mode "%s".', $tie));
        }
    }

    public function getName(): string
    {
        return Query::CURSOR;
    }

    public function getSQL(): array
    {
        $param = 'cursor_'.($this->before ? 'before' : 'after');
        $operator = $this->before ? '<' : '>';

        $tie = match ($this->tie) {
            self::TIE_ALL => '1 = 1',
            self::TIE_ID => \sprintf('at.id %s :%s_id', $operator, $param),
            self::TIE_NONE => '1 = 0',
        };

        $sql = \sprintf(
            "(at.created_at %1\$s :%2\$s_date OR (at.created_at = :%2\$s_date AND (COALESCE(at.transaction_id, '') %1\$s :%2\$s_tx OR (COALESCE(at.transaction_id, '') = :%2\$s_tx AND %3\$s))))",
            $operator,
            $param,
            $tie,
        );

        $params = [
            $param.'_date' => $this->createdAt,
            $param.'_tx' => $this->transactionId,
        ];

        if (self::TIE_ID === $this->tie) {
            $params[$param.'_id'] = $this->id;
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
