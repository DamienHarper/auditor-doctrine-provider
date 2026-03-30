# Filters Reference

> **Fine-grained control over audit queries**

Filters allow you to restrict the results returned by a `Query`. They can be added via `createQuery()` options or directly on the `Query` object.

## 📋 Available Filters

| Filter             | Class             | Description                                    |
|--------------------|-------------------|------------------------------------------------|
| `SimpleFilter`     | `SimpleFilter`    | Equality or `IN` match on a column             |
| `DateRangeFilter`  | `DateRangeFilter` | Filter by a date range on a datetime column    |
| `RangeFilter`      | `RangeFilter`     | Filter by a numeric range                      |
| `NullFilter`       | `NullFilter`      | Filter for NULL / NOT NULL values              |
| `JsonFilter`       | `JsonFilter`      | Filter by a value inside the diffs JSON column |

All filter classes live under:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\{FilterClass};
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
```

## 🔵 SimpleFilter

Matches a single value or a list of values (generates `= ?` or `IN (?,?)` SQL).

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

// Match a single value
$query->addFilter(new SimpleFilter(Query::TYPE, 'update'));

// Match multiple values (IN clause)
$query->addFilter(new SimpleFilter(Query::TYPE, ['insert', 'update']));

// Filter by user ID
$query->addFilter(new SimpleFilter(Query::USER_ID, 'user-42'));

// Filter by entity ID
$query->addFilter(new SimpleFilter(Query::OBJECT_ID, '100'));
```

### Supported Columns

| Constant                    | Column           |
|-----------------------------|------------------|
| `Query::ID`                 | `id`             |
| `Query::TYPE`               | `type`           |
| `Query::OBJECT_ID`          | `object_id`      |
| `Query::DISCRIMINATOR`      | `discriminator`  |
| `Query::TRANSACTION_ID`     | `transaction_id` |
| `Query::USER_ID`            | `blame_id`       |

> [!NOTE]
> `Query::TRANSACTION_HASH` is a deprecated alias for `Query::TRANSACTION_ID`.

## 📅 DateRangeFilter

Filters entries by a date range on `created_at`.

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

// Entries created in January 2024
$query->addFilter(new DateRangeFilter(
    Query::CREATED_AT,
    new \DateTimeImmutable('2024-01-01'),
    new \DateTimeImmutable('2024-01-31')
));

// Entries created after a given date (open end)
$query->addFilter(new DateRangeFilter(
    Query::CREATED_AT,
    new \DateTimeImmutable('2024-06-01'),
    null
));

// Entries created before a given date (open start)
$query->addFilter(new DateRangeFilter(
    Query::CREATED_AT,
    null,
    new \DateTimeImmutable('2024-06-01')
));
```

## 🔢 RangeFilter

Filters entries by a numeric range (e.g., `id` between two values).

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

// Entries with ID between 100 and 200
$query->addFilter(new RangeFilter(Query::ID, 100, 200));

// IDs greater than 500 (open end)
$query->addFilter(new RangeFilter(Query::ID, 500, null));
```

## ❌ NullFilter

Filters entries where a column is `NULL` or `NOT NULL`.

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

// Entries where blame_id IS NULL (anonymous changes)
$query->addFilter(new NullFilter(Query::USER_ID, isNull: true));

// Entries where blame_id IS NOT NULL (authenticated changes)
$query->addFilter(new NullFilter(Query::USER_ID, isNull: false));
```

> [!WARNING]
> `NullFilter` works on indexed columns such as `blame_id`, `object_id`, `type`, etc. Do not use `Query::JSON` with `NullFilter` — there is no `json` column in the audit table. To find entries where `extra_data IS NULL`, query without a filter and check `$entry->extraData === null`.

## 🔍 JsonFilter

Filters entries by the presence of a field key within the `diffs` JSON column. Useful to find all audits where a specific field was changed.

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

// Entries where the 'title' field was part of the diff
$query->addFilter(new JsonFilter(Query::JSON, 'title'));
```

> [!NOTE]
> `JsonFilter` implements `PlatformAwareFilterInterface`. The exact SQL it generates is platform-specific:
> - **MySQL / MariaDB** — Uses `JSON_CONTAINS_PATH(diffs, 'one', '$.title')`
> - **PostgreSQL** — Uses `diffs ? 'title'`
> - **SQLite** — Uses `json_extract(diffs, '$.title') IS NOT NULL`

## 🔗 Combining Filters

Multiple filters are combined with `AND`:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\{DateRangeFilter, SimpleFilter};
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

$query = $reader->createQuery(Post::class);

// All updates by user '42' in the last 30 days
$query->addFilter(new SimpleFilter(Query::TYPE, 'update'));
$query->addFilter(new SimpleFilter(Query::USER_ID, '42'));
$query->addFilter(new DateRangeFilter(
    Query::CREATED_AT,
    new \DateTimeImmutable('-30 days'),
    null
));

$entries = $query->execute();
```

## 📐 Ordering Results

```php
// Default order: created_at DESC, id DESC
// Override:
$query->addOrderBy(Query::CREATED_AT, 'ASC');
$query->addOrderBy(Query::ID, 'ASC');
```

## 📄 Manual Pagination

```php
// 20 results starting at offset 40 (page 3 of 20 per page)
$query->limit(20, 40);
$entries = $query->execute();
```

---

## Related

- 🔍 [Querying Audits](index.md)
- 📦 [Entry Reference](entry.md)
