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
    'batch_size'      => 0,
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
| `storage_mapper` | `callable\|null`  | `null`     | Callback to route audits to storage services     |
| `utf8_convert`   | `bool`            | `false`    | Convert string values to UTF-8 before storing   |
| `batch_size`     | `int` (≥ 0)       | `0`        | Write audit entries with multi-row INSERTs (see Batch INSERT below) |

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
| `roles`           | `array`    | `null`  | View permissions (`['view' => ['ROLE_X']]`)     |
| `max_age`         | `?string`  | `null`  | Retention: ISO 8601 duration applied by `audit:clean` instead of the global cutoff |
| `max_entries`     | `?int`     | `null`  | Retention: number of most recent entries kept by `audit:clean` |

`max_age` and `max_entries` take precedence over the `#[Auditable(maxAge: ..., maxEntries: ...)]` values, key by key:
setting only `max_age` keeps the attribute `maxEntries`, and an explicit `null` disables the attribute value.
They are validated (positive ISO 8601 duration, positive integer) before `audit:clean` deletes anything.

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

## 📦 Batch INSERT

By default (`batch_size: 0`), each audit entry is written with its own `INSERT` statement as soon as it is produced.
A positive `batch_size` buffers the audit entries of the database transaction being committed and writes them with
multi-row `INSERT` statements, which saves round trips to the database when a transaction changes many entities:

```php
$configuration = new Configuration([
    'batch_size' => 500,
]);
```

- **When**: the buffered audit entries are written right before the `COMMIT` of the audited transaction, or as soon
  as `batch_size` entries are buffered. Values between `100` and `1000` are a good start: the larger the value, the
  fewer statements, but the more memory the buffer holds for a large transaction.
- **Per-statement limit**: whatever `batch_size`, a single statement holds at most 99 rows on SQLite (bound parameter
  limit of older SQLite versions) and 500 rows on MySQL, MariaDB and PostgreSQL. Other platforms are limited to 99 rows
  per statement and must support the multi-row `INSERT INTO … VALUES (…), (…)` syntax.
- **No `id` in the payload**: the `LifecycleEvent` payload of a buffered audit entry has no `id` key, since the entry is
  not written yet when it is persisted. With `batch_size: 0`, the provider adds the `id` of the inserted row to the
  payload once the entry is written.
- **Failures fail the commit**: if the buffered audit entries cannot be written, the commit fails and the audited
  transaction is rolled back, so that no change is committed without its audit entries. This includes audit entries
  stored on another connection (see [Multi-Database Configuration](multi-database.md)): those already written by an
  early batch are deleted when the audited transaction is rolled back, as they are with `batch_size: 0`.
- **Outside of a commit**: `DoctrineProvider::persist()` called directly, outside of the commit of an audited
  transaction, still writes the audit entry immediately.

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

1. PHP attributes on entities are loaded first (via `AttributeLoader`)
2. Programmatic configuration (the `entities` array) takes precedence over attributes
3. Global `ignored_columns` are combined with entity-specific ones

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
            'roles' => ['view' => ['ROLE_ADMIN']],  // Overrides attribute
        ],
    ],
]);

// Final configuration for Post:
// - ignored_columns: ['cached', 'slug', 'updatedAt']
// - roles: ['view' => ['ROLE_ADMIN']]
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
]);
```

---

## Next Steps

- 🏷️ [Attributes Reference](attributes.md)
- 🗄️ [Multi-Database Setup](multi-database.md)
- 🛠️ [Schema Management](schema.md)
