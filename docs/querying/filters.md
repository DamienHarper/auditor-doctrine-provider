---
description: "Reference of the audit query filters: SimpleFilter, DateRangeFilter, RangeFilter, NullFilter, JsonFilter, ChangedFieldFilter, BlameOriginFilter and RootFilter."
---

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
| `JsonFilter`       | `JsonFilter`      | Filter by a value inside a JSON column, typically `extra_data` |
| `ChangedFieldFilter` | `ChangedFieldFilter` | Entries whose diff touches a given field (or one of several) |
| `BlameOriginFilter` | `BlameOriginFilter` | Entries made from some origins: web request, console, worker, system |
| `ImpersonatorFilter` | `ImpersonatorFilter` | Entries made while a user impersonated another one, optionally by a given impersonator |
| `RootFilter`       | `RootFilter`      | Entries indexed under an audit root (see [Histories](history.md)) |

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
> `Query::TRANSACTION_HASH` is kept for backward compatibility and maps to `transaction_id`
> (same as `Query::TRANSACTION_ID`). Use `Query::TRANSACTION_ID` in new code.

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
$query->addFilter(new NullFilter(Query::USER_ID));
```

`NullFilter` only tests `IS NULL`. To keep the entries whose column is not null, use a `RangeFilter` or a
`SimpleFilter` with the values you expect, or a `JsonFilter` with `IS NOT NULL` on a JSON column.

> [!WARNING]
> `NullFilter` works on indexed columns such as `blame_id`, `object_id`, `type`, etc. Do not use `Query::JSON` with `NullFilter` — there is no `json` column in the audit table. To find entries where `extra_data IS NULL`, query without a filter and check `$entry->extraData === null`.

## 🔍 JsonFilter

Filters entries on a value inside a JSON column, typically `extra_data`:
`new JsonFilter($column, $path, $value = null, $operator = '=', $strict = false)`.

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;

// Entries whose extra_data.department is 'IT'
$query->addFilter(new JsonFilter('extra_data', 'department', 'IT'));

// Entries whose extra_data has a non-null 'route'
$query->addFilter(new JsonFilter('extra_data', 'route', null, 'IS NOT NULL'));
```

Operators: `=`, `!=`, `<>`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS NULL`, `IS NOT NULL`. On a platform without JSON
support, the filter falls back to `LIKE` unless `$strict` is `true` (it then throws). See
[Extra Data](../extra-data.md#filtering-by-extra-data) for the platforms and indexing.

> [!TIP]
> To find the entries that changed a field, use [`ChangedFieldFilter`](#-changedfieldfilter) rather than a
> `JsonFilter` on `diffs`.

## ✏️ ChangedFieldFilter

Restricts the results to the entries whose diff touches a given field, whatever the operation:

- inserts and updates that changed the field;
- removes recorded with `full_snapshot_on_remove` enabled (removes without a snapshot carry no field);
- associations and dissociations recorded on the field, so that filtering on a collection returns the items added to or removed from it.

Both diff formats are read: the schema v2 envelope (including the rows converted by `audit:schema:migrate --convert-diffs`) and the legacy rows (`schema_version = 1`) that were not converted yet.

### Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ChangedFieldFilter;

// Through the createQuery() option
$query = $reader->createQuery(Post::class, ['changed_field' => 'title']);

// Or directly on the query
$query->addFilter(new ChangedFieldFilter('tags'));

// Fields of embeddables use their dotted name
$query->addFilter(new ChangedFieldFilter('address.street'));

// Entries that changed any of several fields
$query = $reader->createQuery(Post::class, ['changed_field' => ['title', 'body']]);
```

The field name must be a Doctrine field name (letters, digits and underscores, dotted for embeddables), otherwise an `InvalidArgumentException` is thrown. It is always passed to the database as a bound parameter.

> [!NOTE]
> `ChangedFieldFilter` requires native JSON support (MySQL 5.7+, MariaDB 10.2.3+, PostgreSQL 9.4+, SQLite 3.38+) and an audit table with the schema v2 structure: run `audit:schema:migrate` on legacy tables first. It throws an `InvalidArgumentException` otherwise; check `JsonPlatformHelper::isJsonSearchSupported()` beforehand to offer the filter only where it works.

> [!TIP]
> The predicate cannot use an index: the database reads the `diffs` of every row left by the other filters. Combine it with `object_id` or a date range on large audit tables.

## 🧭 BlameOriginFilter

Keeps the entries made from some origins (`DH\Auditor\History\BlameOrigin`): `http` (a web request with a
firewall), `console`, `messenger` (blame context types) or `system` (anything else). Several origins are combined
with `OR`. It reads the `blame` JSON column, so it needs native JSON support and a schema v2 table.

```php
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\BlameOriginFilter;

$query->addFilter(new BlameOriginFilter([BlameOrigin::Console, BlameOrigin::Messenger]));

// Or through the createQuery() option
$query = $reader->createQuery(Post::class, ['blame_origin' => 'http']);
```

## 🎭 ImpersonatorFilter

Keeps the entries made while a user impersonated another one (switch user): those whose `blame` JSON has an
`impersonator` key, written when the user provider returns a user with an impersonator. Given an identifier, it
keeps those of that impersonator only. It reads the `blame` JSON column, so it needs native JSON support and a
schema v2 table.

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\ImpersonatorFilter;

$query->addFilter(new ImpersonatorFilter());     // made by impersonating someone
$query->addFilter(new ImpersonatorFilter('1'));  // made by impersonator #1
```

Entries written before the `impersonator` key existed are not found: auditor-bundle before 8.0 appended the
impersonator to the username instead (`alice[impersonator admin]`).

## 🌳 RootFilter

Keeps the entries indexed under an audit root, optionally through some paths only. It reads the `audit_root` index
table: use it through `Reader::createRootQuery()`, which sets the index table and merges the tables of every entity
(see [Histories](history.md)).

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
