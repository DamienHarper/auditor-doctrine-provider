---
description: "All DoctrineProvider options: audit table prefix and suffix, ignored and audited columns, entity options, batch INSERT, compression, mask secret, storage mapper."
---

# DoctrineProvider Configuration

> **All configuration options for the DoctrineProvider**

This page covers all configuration options for the DoctrineProvider.

## ⚙️ Configuration Options

```php
<?php

use DH\Auditor\Provider\Doctrine\Configuration;

$configuration = new Configuration([
    'table_prefix'    => '',
    'table_suffix'    => '_audit',
    'ignored_columns' => [],
    'entities'        => [],
    'viewer'          => true,
    'storage_mapper'  => null,
    'utf8_convert'    => false,
    'full_snapshot_on_remove' => false,
    'mask_secret'     => null,
    'batch_size'      => 0,
    'skip_disabled_entities' => false,
    'compression'     => false,
    'ignored_columns_resolver' => null,
    'root_resolver'   => null,
    'entity_label_resolver' => null,
    'read_scope'      => null,
]);
```

### Options Reference

| Option           | Type              | Default    | Description                                      |
|------------------|-------------------|------------|--------------------------------------------------|
| `table_prefix`   | `string`          | `''`       | Prefix for audit table names                     |
| `table_suffix`   | `string`          | `'_audit'` | Suffix for audit table names                     |
| `ignored_columns`| `array`           | `[]`       | Properties to ignore globally across all entities |
| `entities`       | `array`           | `[]`       | Entity-specific configuration                    |
| `viewer`         | `bool\|array`     | `true`     | Enable/configure the audit viewer                |
| `storage_mapper` | `callable\|class-string\|null` | `null` | Callback, or class name of an invokable class without constructor arguments, routing audits to storage services |
| `utf8_convert`   | `bool`            | `false`    | Convert string values to UTF-8 before storing   |
| `full_snapshot_on_remove` | `bool`   | `false`    | Record every audited field of a removed entity (see below) |
| `mask_secret`    | `string\|null`    | `null`     | Secret of the `#[Mask(strategy: MaskStrategy::KeyedHash)]` fields, at least 32 bytes (see below) |
| `batch_size`     | `int` (≥ 0)       | `0`        | Write audit entries with multi-row INSERTs (see Batch INSERT below) |
| `skip_disabled_entities` | `bool`    | `false`    | No audit table for entities disabled by their configuration (see below) |
| `compression`    | `bool`            | `false`    | Store `diffs`/`extra_data` compressed (MariaDB)  |
| `ignored_columns_resolver` | `callable\|null` | `null` | Returns the properties to ignore for a given entity object (see below) |
| `root_resolver`  | `callable\|null`  | `null`     | Returns extra audit roots of an entity object (see [Histories](../../querying/history.md)) |
| `entity_label_resolver` | `callable\|null` | `null` | Builds the label of an entity written in the diffs (see [Histories](../../querying/history.md#-entity-labels)) |
| `read_scope`     | `callable\|null`  | `null`     | Applied to every query the reader creates, to restrict what can be read (see [Histories](../../querying/history.md#-read-scope)) |

## 📛 Table Naming

Audit tables are named based on the original entity table name with optional prefix and suffix:

```
[table_prefix] + [entity_table_name] + [table_suffix]
```

### Examples

```php
// Default: posts → posts_audit
$configuration = new Configuration([
    'table_suffix' => '_audit',
]);

// Custom prefix: posts → audit_posts
$configuration = new Configuration([
    'table_prefix' => 'audit_',
    'table_suffix' => '',
]);

// Both: posts → audit_posts_log
$configuration = new Configuration([
    'table_prefix' => 'audit_',
    'table_suffix' => '_log',
]);
```

## 🚫 Ignored Properties

> [!NOTE]
> `ignored_columns` expects mapped **PHP property names** (e.g. `createdAt`), not SQL column names (e.g. `created_at`).
> A column name that differs from its property name is silently not ignored.

### Global Ignored Properties

Properties that should never be audited across all entities:

```php
$configuration = new Configuration([
    'ignored_columns' => [
        'createdAt',
        'updatedAt',
    ],
]);
```

### Per-Entity Ignored Properties

Ignore additional properties for specific entities (combined with the global list):

```php
$configuration = new Configuration([
    'entities' => [
        App\Entity\User::class => [
            'ignored_columns' => ['password', 'salt'],
        ],
    ],
]);
```

### Audited Properties (Allowlist)

When only a few properties of an entity matter, list them with `audited_columns` instead of ignoring all the others:

```php
$configuration = new Configuration([
    'entities' => [
        App\Entity\Order::class => [
            'audited_columns' => ['status', 'total', 'customer'],
        ],
    ],
]);
```

- Only the listed properties (fields and to-one associations) appear in the diffs; an update that only changes
  other properties writes no audit entry. Inserts and removals are still audited.
- `null` or no `audited_columns` (the default) audits every property; `[]` audits no property, so only inserts and
  removals are recorded (with empty diffs, or the allowlisted properties with `full_snapshot_on_remove`).
- The identifier is not audited as a field unless it is listed, but every entry still records it (`object_id` and
  the diff `source`).
- An allowlist cannot audit an ignored property: global and per-entity `ignored_columns`, `#[Ignore]` and the
  `ignored_columns_resolver` always win (see [Which properties are audited](#which-properties-are-audited)).

### Ignoring Properties per Object

`ignored_columns` and `audited_columns` apply to every object of an entity class. To decide per object (e.g. skip
a field while a document is a draft), give `ignored_columns_resolver` a callable that receives the entity object
and returns the properties to ignore for it:

```php
use App\Entity\Document;
use DH\Auditor\Provider\Doctrine\Auditing\IgnoredColumnsResolverInterface;

final class DocumentIgnoredColumnsResolver implements IgnoredColumnsResolverInterface
{
    public function __invoke(object $entity): array
    {
        if ($entity instanceof Document && $entity->isDraft()) {
            return ['content'];
        }

        return [];
    }
}

$configuration = new Configuration([
    'ignored_columns_resolver' => new DocumentIgnoredColumnsResolver(),
]);

// or later, with any callable (null removes it)
$configuration->setIgnoredColumnsResolver(static fn (object $entity): array => []);
```

- The resolver is called for every audited entity object, once per insert, update and (with
  `full_snapshot_on_remove`) removal, with the entity as it is when the flush is processed: keep it cheap.
- It must return a list of property names; anything else throws an `UnexpectedValueException`.
- Its result is added to the ignored properties: a resolver can ignore more properties, never audit a property that
  the configuration ignores or leaves out of `audited_columns`.
- `isAuditedField()` applies it when given an entity object, not a class name.

### Which Properties Are Audited

A property is audited when its entity is audited and the property is:

1. not in the global `ignored_columns`,
2. not in the entity `ignored_columns` nor marked with `#[Ignore]`,
3. listed in the entity `audited_columns`, when the entity has one,
4. not returned by the `ignored_columns_resolver` for that object, when one is set.

Ignoring always wins: a property listed in `audited_columns` but ignored by any other rule is not audited.

> [!NOTE]
> These rules apply to fields and to-one associations, which are recorded in the diffs. Changes of to-many
> associations are recorded as `associate` / `dissociate` entries and are not filtered by property.

## 🏷️ Entity Configuration

Configure entities programmatically instead of (or in addition to) using attributes:

```php
$configuration = new Configuration([
    'entities' => [
        App\Entity\User::class => [
            'enabled'         => true,
            'ignored_columns' => ['password', 'salt'],
            'roles'           => [
                'view' => ['ROLE_ADMIN'],  // Who can view these audits
            ],
        ],
        App\Entity\Post::class => [
            'enabled' => true,
        ],
        App\Entity\Draft::class => [
            'enabled' => false,  // Disabled even if has #[Auditable]
        ],
    ],
]);
```

### Entity Options

| Option            | Type       | Default | Description                                     |
|-------------------|------------|---------|-------------------------------------------------|
| `enabled`         | `bool`     | `true`  | Whether auditing is enabled for this entity     |
| `ignored_columns` | `array`    | `[]`    | Properties to ignore for this entity            |
| `audited_columns` | `?array`   | `null`  | Allowlist: only these properties are audited (`null`: all) |
| `roles`           | `array`    | `null`  | View permissions (`['view' => ['ROLE_X']]`)     |
| `max_age`         | `?string`  | `null`  | Retention: ISO 8601 duration applied by `audit:clean` instead of the global cutoff |
| `max_entries`     | `?int`     | `null`  | Retention: number of most recent entries kept by `audit:clean` |
| `roots`           | `list<string>` | `[]` | Audit root paths, merged with `#[AuditRoot]` (see [Histories](../../querying/history.md)) |

`max_age` and `max_entries` take precedence over the `#[Auditable(maxAge: ..., maxEntries: ...)]` values, key by key:
setting only `max_age` keeps the attribute `maxEntries`, and an explicit `null` disables the attribute value.
They are validated (positive ISO 8601 duration, positive integer) before `audit:clean` deletes anything.

For an entity marked `#[Auditable]`, the configured options combine with the attributes:

- `enabled: false` disables the entity despite `#[Auditable]`; `enabled: true` does not re-enable an
  `#[Auditable(enabled: false)]` entity.
- `roles` take precedence over `#[Security]`, scope by scope (`view`): `roles: null`, an empty array or a missing
  `view` key keeps the attribute value.
- `ignored_columns` and `roots` are added to the `#[Ignore]` and `#[AuditRoot]` ones; `audited_columns` only exists
  in the configuration.

## 👁️ Viewer Configuration

The `viewer` option controls the built-in audit viewer (when integrated with a UI layer):

```php
// Enable with defaults
$configuration = new Configuration([
    'viewer' => true,
]);

// Disable
$configuration = new Configuration([
    'viewer' => false,
]);

// Custom page size
$configuration = new Configuration([
    'viewer' => [
        'enabled'   => true,
        'page_size' => 25,  // Default is 50
    ],
]);
```

## 🌐 UTF-8 Conversion

The `utf8_convert` option ensures string values are converted to valid UTF-8 before being stored in the `diffs` JSON column. Useful when your database or entities may contain non-UTF-8 encoded data:

```php
$configuration = new Configuration([
    'utf8_convert' => true,
]);
```

## 📸 Full Snapshot on Remove

By default, a `remove` entry only identifies the deleted entity: its diff holds the `source` block (id, class, label,
table) and an empty `changes`. With `full_snapshot_on_remove`, it also records the value of every audited field
before the deletion, as `old` (with `new` set to `null`), so that the last state of a deleted entity can be read
from its audit history. A generated identifier is left out of these fields, as in an `insert` entry: Doctrine resets
it on deletion, and the `source` block holds it. The owning-side to-one associations (`ManyToOne`, owning `OneToOne`)
are recorded as in an `insert` entry, with the summary of their target (id, class, label, table) as `old`; a null
association is left out, and so are to-many associations. The targets that are not loaded yet are loaded with one
query per target class, and a target deleted in the same flush keeps the identifier it had before the deletion.

```php
$configuration = new Configuration([
    'full_snapshot_on_remove' => true,
]);
```

The snapshot makes `remove` entries larger, and lets `ChangedFieldFilter` match them on any of their fields.

## 🎭 Masked Values

`mask_secret` keys the HMAC-SHA256 that `#[Mask(strategy: MaskStrategy::KeyedHash)]` stores instead of a value
(see [Attributes](attributes.md#-mask)). It must be at least 32 bytes long. Without it, an entity with a keyed hash
mask fails when the attributes are loaded.

```php
$configuration = new Configuration([
    'mask_secret' => $_ENV['AUDIT_MASK_SECRET'],
]);
```

Changing the secret changes the hashes of the values recorded from then on: the entries recorded before can no
longer be matched with the new ones.

## 📦 Batch INSERT

By default (`batch_size: 0`), each audit entry is written with its own `INSERT` statement as soon as it is produced.
A positive `batch_size` buffers the audit entries of the database transaction being committed and writes them with
multi-row `INSERT` statements, which saves round trips to the database when a transaction changes many entities:

```php
$configuration = new Configuration([
    'batch_size' => 500,
]);
```

> [!TIP]
> With 500 to 1,000 audited entities per flush, `batch_size: 500` made a flush 10 to 18 % faster on SQLite, 19 to
> 51 % faster on MySQL and 44 to 67 % faster on PostgreSQL in our measurements: see [Performance](performance.md) for
> the method, the full results and how to reproduce them.

**Why is it off by default?** Only because of the `id`: a `LifecycleEvent` listener, or another storage provider, that
reads `$payload['id']` gets no `id` from a buffered entry (see below). If nothing in your application reads it, a
positive `batch_size` is the largest speed-up available for flushes of many entities, and turning it on changes
nothing else: the entries are written in the same database transaction, and a failure still fails the commit.

| You read `$payload['id']` in a listener or another provider? | Recommended `batch_size`          |
|---------------------------------------------------------------|-----------------------------------|
| No                                                            | `500`                             |
| Yes                                                           | `0` (default): the `id` is set    |

- **When**: the buffered audit entries are written right before the `COMMIT` of the audited transaction, or as soon
  as `batch_size` entries are buffered. Values between `100` and `1000` are a good start: the larger the value, the
  fewer statements, but the more memory the buffer holds for a large transaction.
- **Per-statement limit**: whatever `batch_size`, a single statement holds at most 99 rows on SQLite (bound parameter
  limit of older SQLite versions) and 500 rows on MySQL, MariaDB and PostgreSQL. Other platforms are limited to 99 rows
  per statement and must support the multi-row `INSERT INTO … VALUES (…), (…)` syntax. A statement is also closed
  before its string values (diffs, extra data, blame…) exceed 1 MiB, a larger audit entry being written alone: MySQL
  and MariaDB reject a statement larger than their `max_allowed_packet` setting (64 MiB by default since MySQL 8.0,
  as low as 1 MiB on older or tuned servers). Keep `max_allowed_packet` comfortably above 1 MiB, and above the size
  of your largest audit entry (as with `batch_size: 0`).
- **No `id` in the payload**: the `LifecycleEvent` payload of a buffered audit entry has no `id` key, since the entry is
  not written yet when it is persisted. With `batch_size: 0`, the provider adds the `id` of the inserted row to the
  payload once the entry is written (on PostgreSQL, through `INSERT … RETURNING id`, in the same round trip).
- **Failures fail the commit**: if an audit entry cannot be buffered (e.g. the storage mapper throws) or the buffered
  audit entries cannot be written, the commit fails with the first of these failures and the audited transaction is
  rolled back, so that no change is committed without its audit entries (as with `batch_size: 0`, where a failure to
  write an audit entry also fails the flush). This includes audit entries
  stored on another connection (see [Multi-Database Configuration](multi-database.md)): those already written by an
  early batch are deleted when the audited transaction is rolled back, as they are with `batch_size: 0`.
- **Outside of a commit**: `DoctrineProvider::persist()` called directly, outside of the commit of an audited
  transaction, still writes the audit entry immediately.
## 🗜️ Compression

On MySQL and MariaDB, audit tables can become the largest tables of a database: almost all of their size is the
`diffs` column (and `extra_data` when used). The `compression` option stores these two columns compressed:

```php
$configuration = new Configuration([
    'compression' => true,
]);
```

| Platform   | Effect                                                                                       |
|------------|----------------------------------------------------------------------------------------------|
| MariaDB    | `diffs` and `extra_data` are declared `JSON COMPRESSED` (transparent column compression)      |
| PostgreSQL | None: large `JSONB` values are already compressed by PostgreSQL (TOAST)                      |
| MySQL      | None: MySQL has no column compression (see [Schema Management](schema.md#mysql--mariadb))     |
| SQLite     | None                                                                                          |

MariaDB compresses and decompresses the values transparently: reading audit entries, `JsonFilter` and JSON functions in
SQL work unchanged. Values shorter than `column_compression_threshold` (100 bytes by default) are stored as is.

Compression trades CPU for disk space and I/O. Reading a large history whose pages are already in memory gets slower,
since every value has to be decompressed: in the contributor's measurements (34,279 audit rows, MariaDB 11.8), reading
an entity with ~510 changes took 16.0 ms instead of 7.5 ms (median, warm cache). Reads that hit the disk get faster
(59.8 ms instead of 138.6 ms, cold cache) and writes are not slower, since there is much less data to read and write.

New audit tables are created with compressed columns. Existing audit tables are converted by
`audit:schema:update --force` (or by a migration containing the SQL shown by `audit:schema:update --dump-sql`):
altering the columns rewrites the table and compresses existing entries. Disabling the option and running
`audit:schema:update` again converts the columns back, without losing data.

> [!NOTE]
> The schema comparator of Doctrine DBAL does not see the `COMPRESSED` attribute, so `doctrine:schema:update` and
> `doctrine:migrations:diff` neither report nor change the compression of existing audit tables: use
> `audit:schema:update` for that. Conversely, they never report a difference for compressed columns.

> [!WARNING]
> Converting existing tables rewrites them (`ALTER TABLE ... MODIFY`), which takes time on large tables. Before
> MariaDB 11.2, writes to the audit table are also locked for the duration of the rewrite, so audited flushes wait for
> it; since MariaDB 11.2, the rewrite runs online by default (`ALGORITHM=COPY, LOCK=NONE`). Plan it like any other
> large schema migration.

## ⏭️ Skipping Disabled Entities

An entity disabled by its configuration (`#[Auditable(enabled: false)]`, or `'enabled' => false`, also on an `#[Auditable]` entity) is not audited, but
it still gets an audit table: auditing can be enabled for it at runtime with `enableAuditFor()`, and the audit
entries need somewhere to go. When you never enable such entities at runtime, `skip_disabled_entities` leaves them
without an audit table:

```php
$configuration = new Configuration([
    'skip_disabled_entities' => true,
]);
```

- `audit:schema:update`, `doctrine:schema:update` and `doctrine:migrations:diff` all skip these entities, so the
  result does not depend on the tool that manages your schema. Existing audit tables of these entities are left as
  they are: drop them yourself if you no longer need them.
- `enableAuditFor()` throws a `LogicException` for these entities, instead of a failing flush later on. Entities
  enabled by their configuration and disabled at runtime with `disableAuditFor()` keep their audit table.
- The `Reader` refuses to query these entities and ignores them when reading a transaction.
- Entities configured without an `enabled` key are enabled, so they are never skipped.

## 🗄️ Storage Mapper

When using multiple storage EntityManagers, the storage mapper determines where to store audits for each entity.

> [!NOTE]
> See [Multi-Database Configuration](multi-database.md) for detailed information.

```php
use DH\Auditor\Provider\Doctrine\Service\StorageService;

$configuration = new Configuration([
    'storage_mapper' => function (string $entity, array $storageServices): StorageService {
        // Route User audits to a dedicated storage
        if ($entity === App\Entity\User::class) {
            return $storageServices['users_audit_storage'];
        }

        // Default storage for everything else
        return $storageServices['default'];
    },
]);
```

## 🔍 Checking Audit Status

```php
// Check if an entity class is configured for auditing
$provider->isAuditable(App\Entity\Post::class);  // bool

// Check if auditing is currently active (respects enabled flag)
$provider->isAudited(App\Entity\Post::class);  // bool

// Check if a specific field is being audited
$provider->isAuditedField(App\Entity\Post::class, 'title');    // true
$provider->isAuditedField(App\Entity\Post::class, 'password'); // false (if ignored)
```

## 🔀 Configuration Merging

> [!TIP]
> Configuration from PHP attributes and programmatic config are merged intelligently.

1. PHP attributes on entities are loaded first (via `AttributeLoader`): for an entity with `#[Auditable]`, they
   provide `enabled`, `roles` (`#[Security]`) and the `#[Ignore]` properties
2. `enabled: false` in the programmatic configuration disables the entity despite `#[Auditable]` (`enabled: true`
   cannot re-enable an `#[Auditable(enabled: false)]` entity), and its `roles` take precedence over `#[Security]`,
   scope by scope
3. The entity `ignored_columns` and `roots` of the programmatic configuration are added to the `#[Ignore]` properties
   and `#[AuditRoot]` paths
4. The entity `audited_columns` and the retention options (`max_age`, `max_entries`, key by key) of the programmatic
   configuration are kept
5. Global `ignored_columns` and the `ignored_columns_resolver` apply on top of the entity options

```php
// Given this entity with attributes:
#[Auditable]
#[Security(view: ['ROLE_USER'])]
class Post
{
    #[Ignore]
    private string $cached = '';
}

// And this programmatic config:
$configuration = new Configuration([
    'ignored_columns' => ['updatedAt'],
    'entities' => [
        Post::class => [
            'ignored_columns' => ['slug'],
            'audited_columns' => ['title', 'slug', 'cached', 'updatedAt'],
        ],
    ],
]);

// Final configuration for Post:
// - entity ignored_columns: ['cached', 'slug'] (+ the global 'updatedAt')
// - audited_columns: ['title', 'slug', 'cached', 'updatedAt'] => only 'title' is audited
// - roles: ['view' => ['ROLE_USER']]
```

## 📄 Complete Example

```php
<?php

use DH\Auditor\Provider\Doctrine\Configuration;
use App\Entity\{User, Post, Comment, Order, Payment};

$configuration = new Configuration([
    'table_prefix' => '',
    'table_suffix' => '_audit',

    'ignored_columns' => [
        'createdAt',
        'updatedAt',
    ],

    'entities' => [
        User::class => [
            'enabled'         => true,
            'ignored_columns' => ['password', 'salt'],
            'roles'           => ['view' => ['ROLE_ADMIN']],
        ],
        Post::class => [
            'enabled' => true,
        ],
        Comment::class => [
            'enabled' => true,
            'roles'   => ['view' => ['ROLE_MODERATOR', 'ROLE_ADMIN']],
        ],
        Order::class => [
            'enabled' => true,
        ],
        Payment::class => [
            'enabled' => true,
            'roles'   => ['view' => ['ROLE_ACCOUNTANT', 'ROLE_ADMIN']],
        ],
    ],

    'viewer' => [
        'enabled'   => true,
        'page_size' => 50,
    ],

    'storage_mapper' => null,  // Single database, no mapper needed

    'compression' => true,     // MariaDB only, ignored on other platforms
]);
```

---

## Next Steps

- 🏷️ [Attributes Reference](attributes.md)
- 🗄️ [Multi-Database Setup](multi-database.md)
- 🛠️ [Schema Management](schema.md)
- 📊 [Performance](performance.md)
