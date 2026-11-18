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
    'compression'     => false,
]);
```

### Options Reference

| Option           | Type              | Default    | Description                                      |
|------------------|-------------------|------------|--------------------------------------------------|
| `table_prefix`   | `string`          | `''`       | Prefix for audit table names                     |
| `table_suffix`   | `string`          | `'_audit'` | Suffix for audit table names                     |
| `ignored_columns`| `array`           | `[]`       | Columns to ignore globally across all entities   |
| `entities`       | `array`           | `[]`       | Entity-specific configuration                    |
| `viewer`         | `bool\|array`     | `true`     | Enable/configure the audit viewer                |
| `storage_mapper` | `callable\|null`  | `null`     | Callback to route audits to storage services     |
| `utf8_convert`   | `bool`            | `false`    | Convert string values to UTF-8 before storing   |
| `compression`    | `bool`            | `false`    | Store `diffs`/`extra_data` compressed (MariaDB)  |

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

## 🚫 Ignored Columns

### Global Ignored Columns

Columns that should never be audited across all entities:

```php
$configuration = new Configuration([
    'ignored_columns' => [
        'created_at',
        'updated_at',
    ],
]);
```

### Per-Entity Ignored Columns

Override ignored columns for specific entities:

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
| `ignored_columns` | `array`    | `[]`    | Columns to ignore for this entity               |
| `roles`           | `array`    | `null`  | View permissions (`['view' => ['ROLE_X']]`)     |

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

New audit tables are created with compressed columns. Existing audit tables are converted by
`audit:schema:update --force` (or by a migration containing the SQL shown by `audit:schema:update --dump-sql`):
altering the columns rewrites the table and compresses existing entries. Disabling the option and running
`audit:schema:update` again converts the columns back, without losing data.

> [!NOTE]
> The schema comparator of Doctrine DBAL does not see the `COMPRESSED` attribute, so `doctrine:schema:update` and
> `doctrine:migrations:diff` neither report nor change the compression of existing audit tables: use
> `audit:schema:update` for that. Conversely, they never report a difference for compressed columns.

> [!WARNING]
> Converting existing tables rewrites them (`ALTER TABLE ... MODIFY`), which takes time and locks writes to the audit
> table on large tables. Plan it like any other large schema migration.

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
    'ignored_columns' => ['updated_at'],
    'entities' => [
        Post::class => [
            'ignored_columns' => ['slug'],
            'roles' => ['view' => ['ROLE_ADMIN']],  // Overrides attribute
        ],
    ],
]);

// Final configuration for Post:
// - ignored_columns: ['cached', 'slug', 'updated_at']
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
        'created_at',
        'updated_at',
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
