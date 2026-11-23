<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Reader\Filter\ChangedFieldFilterTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Restricts a query to the audit entries whose diff touches a given field.
 *
 * Matches, whatever the operation type:
 *  - entries in the schema v2 format (schema_version >= 2, including rows converted by
 *    `audit:schema:migrate --convert-diffs`) whose `changes` contain the field — inserts,
 *    updates, and removes recorded with a full snapshot;
 *  - legacy entries (schema_version = 1, not converted yet) of type insert or update whose
 *    diff has the field as a top-level key;
 *  - associate/dissociate entries recorded on the field (`source.field`), so that filtering
 *    on a collection returns the items added to or removed from it.
 *
 * Removes recorded without a full snapshot carry no field and never match.
 *
 * Given several fields, an entry matches when it touches any of them.
 *
 * The audit table must have the schema v2 structure (`schema_version` column): run
 * `audit:schema:migrate` on legacy tables first. Requires native JSON support
 * (see JsonPlatformHelper::isJsonSearchSupported()).
 *
 * @see ChangedFieldFilterTest
 */
final readonly class ChangedFieldFilter implements PlatformAwareFilterInterface
{
    /**
     * A Doctrine field name: a PHP identifier, possibly dotted for embeddables (e.g. "address.street").
     */
    private const string FIELD_PATTERN = '/^[\p{L}_][\p{L}\p{N}_]*(?:\.[\p{L}_][\p{L}\p{N}_]*)*$/u';

    /**
     * @throws InvalidArgumentException If the field name is not a valid Doctrine field name
     */
    /**
     * @var non-empty-list<string>
     */
    private array $fields;

    /**
     * @param list<string>|string $field one field, or several fields of which any must be touched
     */
    public function __construct(array|string $field)
    {
        $fields = \is_array($field) ? array_values(array_unique($field)) : [$field];
        if ([] === $fields) {
            throw new InvalidArgumentException('A changed field filter needs at least one field.');
        }

        foreach ($fields as $name) {
            if (!\is_string($name) || 1 !== preg_match(self::FIELD_PATTERN, $name)) {
                throw new InvalidArgumentException(\sprintf('Invalid field name "%s".', \is_string($name) ? $name : get_debug_type($name)));
            }
        }

        $this->fields = $fields;
    }

    public function getName(): string
    {
        return Query::DIFFS;
    }

    /**
     * Returns the field, or the first field when several are given.
     */
    public function getField(): string
    {
        return $this->fields[0];
    }

    /**
     * @return non-empty-list<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @throws InvalidArgumentException Always throws as this filter requires a connection
     */
    #[\Deprecated(message: 'Use getSQLWithConnection() instead')]
    public function getSQL(): array
    {
        throw new InvalidArgumentException('ChangedFieldFilter requires a database connection. Use getSQLWithConnection() instead.');
    }

    /**
     * @throws InvalidArgumentException If the database platform has no native JSON support
     */
    public function getSQLWithConnection(Connection $connection): array
    {
        if (!JsonPlatformHelper::isJsonSearchSupported($connection)) {
            throw new InvalidArgumentException(\sprintf('Filtering on a changed field requires native JSON support, which this %s version lacks.', JsonPlatformHelper::getPlatformName($connection)));
        }

        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            // The key is bound as text: "->" is overloaded for text and integer operands.
            $changed = "(CAST(diffs AS jsonb) -> 'changes' -> CAST(:changed_field AS TEXT)) IS NOT NULL";
            $legacyChanged = '(CAST(diffs AS jsonb) -> CAST(:changed_field AS TEXT)) IS NOT NULL';
            $associated = "CAST(diffs AS jsonb) -> 'source' ->> 'field' = :changed_field";
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            // MySQL and MariaDB
            $changed = "JSON_CONTAINS_PATH(diffs, 'one', :changed_field_path) = 1";
            $legacyChanged = "JSON_CONTAINS_PATH(diffs, 'one', :changed_field_legacy_path) = 1";
            $associated = "JSON_UNQUOTE(JSON_EXTRACT(diffs, '$.source.field')) = :changed_field";
        } elseif ($platform instanceof SQLitePlatform) {
            $changed = 'json_type(diffs, :changed_field_path) IS NOT NULL';
            $legacyChanged = 'json_type(diffs, :changed_field_legacy_path) IS NOT NULL';
            $associated = "json_extract(diffs, '$.source.field') = :changed_field";
        } else {
            throw new InvalidArgumentException(\sprintf('Filtering on a changed field is not supported on %s.', JsonPlatformHelper::getPlatformName($connection)));
        }

        $sqls = [];
        $params = [];
        foreach ($this->fields as $field) {
            [$fieldSql, $fieldParams] = $this->fieldSql($field, $changed, $legacyChanged, $associated);
            $sqls[] = $fieldSql;
            $params += $fieldParams;
        }

        $sql = 1 === \count($sqls) ? $sqls[0] : '('.implode(' OR ', $sqls).')';

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function fieldSql(string $field, string $changed, string $legacyChanged, string $associated): array
    {
        // Suffix the parameter names with the field so that several filters can share a query.
        $param = 'changed_field_'.hash('xxh32', $field);
        $changed = str_replace(':changed_field', ':'.$param, $changed);
        $legacyChanged = str_replace(':changed_field', ':'.$param, $legacyChanged);
        $associated = str_replace(':changed_field', ':'.$param, $associated);

        $sql = \sprintf(
            "((schema_version >= 2 AND %s) OR (schema_version < 2 AND type IN ('insert', 'update') AND %s) OR (type IN ('associate', 'dissociate') AND %s))",
            $changed,
            $legacyChanged,
            $associated,
        );

        // The field name only holds letters, digits, underscores and dots, so quoting it as a
        // single JSON path member keeps dotted embeddable fields (e.g. "address.street") whole.
        $params = [$param => $field];
        if (str_contains($sql, ':'.$param.'_path')) {
            $params[$param.'_path'] = \sprintf('$.changes."%s"', $field);
            $params[$param.'_legacy_path'] = \sprintf('$."%s"', $field);
        }

        return [$sql, $params];
    }
}
