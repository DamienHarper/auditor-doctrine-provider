# Schema Management

> **Create and manage audit tables in your database**

This guide covers how to create and manage audit tables using the `SchemaManager`.

## 🔍 Overview

For each audited entity, the provider creates a corresponding audit table to store the change history. The `SchemaManager` class handles all schema operations using DBAL's schema introspection API.

The schema can be managed in two ways:

1. **Automatically** — via Doctrine's `postGenerateSchemaTable` event (integrates with `doctrine:schema:update` / Migrations)
2. **Manually** — by calling `SchemaManager::updateAuditSchema()` directly

## 🏗️ Audit Table Structure (schema version 2)

Each audit table created by the current provider has the following structure:

```sql
CREATE TABLE posts_audit (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schema_version   TINYINT UNSIGNED NOT NULL DEFAULT 2,
    type             VARCHAR(10)  NOT NULL,
    object_id        VARCHAR(255) NOT NULL,
    discriminator    VARCHAR(255) NULL,
    transaction_id   CHAR(26)     NULL,
    diffs            JSON         NULL,
    extra_data       JSON         NULL,
    blame_id         VARCHAR(255) NULL,
    blame            JSON         NULL,
    created_at       DATETIME     NOT NULL,

    INDEX type_idx            (type),
    INDEX object_id_type_idx  (object_id, type),
    INDEX discriminator_idx   (discriminator),
    INDEX transaction_id_idx  (transaction_id),
    INDEX blame_id_created_at_idx (blame_id, created_at),
    INDEX created_at_idx      (created_at)
);
```

### Column Details

| Column           | Type                | Description                                                       |
|------------------|---------------------|-------------------------------------------------------------------|
| `id`             | BIGINT (PK)         | Auto-increment primary key                                        |
| `schema_version` | TINYINT             | Diffs format version: `1` (legacy) or `2` (current)              |
| `type`           | VARCHAR(10)         | Action: insert, update, remove, associate, dissociate             |
| `object_id`      | VARCHAR(255)        | The primary key of the audited entity                             |
| `discriminator`  | VARCHAR(255)        | Entity class (used in inheritance hierarchies)                    |
| `transaction_id` | CHAR(26)            | ULID grouping changes from the same flush batch                   |
| `diffs`          | JSON                | JSON-encoded change data (see format below)                       |
| `extra_data`     | JSON                | Custom extra data (populated via `LifecycleEvent` listener)       |
| `blame_id`       | VARCHAR(255)        | User identifier who made the change                               |
| `blame`          | JSON                | Blame context: `username`, `user_fqdn`, `user_firewall`, `ip`     |
| `created_at`     | DATETIME            | When the audit entry was created                                  |

### Diffs Format (schema version 2)

All entry types share a unified JSON envelope:

```json
{
    "source":  { "id": "42", "class": "App\\Entity\\Post", "label": "Hello World", "table": "posts" },
    "changes": {
        "title":   { "old": "Hello World", "new": "Hello auditor!" },
        "content": { "old": null,           "new": "My first post." }
    }
}
```

- `source` — always present: entity id, FQCN, label, and table at the time of the operation
- `changes` — field-level diff with explicit `old`/`new` for every operation:
  - **INSERT**: `old` is always `null`
  - **UPDATE**: both `old` and `new` reflect the changed values
  - **REMOVE**: `new` is always `null`; `old` holds the full entity snapshot
- **ASSOCIATE / DISSOCIATE** — use a different structure (no `changes` key):
  ```json
  {
      "source":       { "id": "1", "class": "App\\Entity\\Post", "label": "Post #1", "table": "posts", "field": "tags" },
      "target":       { "id": "5", "class": "App\\Entity\\Tag",  "label": "php",     "table": "tag",   "field": "posts" },
      "is_owning_side": true,
      "join_table":   "post__tag"
  }
  ```

### Legacy Diffs Format (schema version 1)

Entries written by earlier versions of the provider carry `schema_version = 1`. The `Entry` model reads them transparently — see [Entry Reference](../../querying/entry.md).

## 🛠️ Using SchemaManager

### Creating the Schema Manager

```php
<?php

use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;

$schemaManager = new SchemaManager($provider);
```

### Updating the Schema (Manual)

Creates audit tables for entities that don't have one yet, and adds missing columns to existing ones:

```php
// Create / update all audit tables
$schemaManager->updateAuditSchema();
```

> [!NOTE]
> `updateAuditSchema()` is safe to run repeatedly. It checks for existing tables and columns before making changes.

### Console Commands

Three console commands are available.

#### audit:schema:update

Creates new audit tables and updates existing ones to match the current schema.

```bash
# Preview the SQL that would be executed
php bin/console audit:schema:update --dump-sql

# Execute the changes
php bin/console audit:schema:update --force

# Both: show SQL and execute
php bin/console audit:schema:update --dump-sql --force
```

#### audit:schema:migrate

Migrates existing audit tables from schema version 1 to schema version 2.

```bash
# Preview the SQL that would be executed (dry-run)
php bin/console audit:schema:migrate --dump-sql

# Execute the migration
php bin/console audit:schema:migrate --force

# Also convert existing v1 diffs JSON to the v2 unified format
php bin/console audit:schema:migrate --force --convert-diffs
```

> [!NOTE]
> Use `audit:schema:migrate` when upgrading an existing installation. New installations created
> with `audit:schema:update` already use schema version 2.

> [!NOTE]
> `--convert-diffs` rewrites existing diffs rows in PHP (batches of 500). This can take a while
> on large tables. Without this flag, legacy rows remain readable via `Entry::getDiffs()` since
> the `Entry` model handles both schema versions transparently.

#### audit:clean

Removes audit entries older than a specified retention period (`P12M` by default).

```bash
# Keep last 6 months (dry run)
php bin/console audit:clean P6M --dry-run

# Execute, skip confirmation (for cron jobs)
php bin/console audit:clean P12M --no-confirm

# Delete before a specific date
php bin/console audit:clean --date=2024-01-01 --no-confirm

# Exclude specific entities
php bin/console audit:clean -x App\\Entity\\User

# Include only specific entities
php bin/console audit:clean -i App\\Entity\\Log
```

> [!NOTE]
> Both commands use Symfony's Lock component to prevent concurrent execution.

#### Registering Commands (Standalone)

When not using auditor-bundle, register the commands manually:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\UpdateSchemaCommand;
use Symfony\Component\Console\Application;

$application = new Application();

$updateCommand = new UpdateSchemaCommand();
$updateCommand->setAuditor($auditor);
$application->add($updateCommand);

$migrateCommand = new MigrateSchemaCommand();
$migrateCommand->setAuditor($auditor);
$application->add($migrateCommand);

$cleanCommand = new CleanAuditLogsCommand();
$cleanCommand->setAuditor($auditor);
$application->add($cleanCommand);

$application->run();
```

### Programmatic Schema Update

```php
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;

$schemaManager = new SchemaManager($provider);

// Get SQL without executing
$sqls = $schemaManager->getUpdateAuditSchemaSql();

// Execute all pending changes
$schemaManager->updateAuditSchema();

// Execute with a progress callback
$schemaManager->updateAuditSchema(null, function (array $progress) {
    echo sprintf('Updated: %s', $progress['table'] ?? '');
});
```

## 📛 Table Naming

### Default Naming

By default, audit tables are named: `{entity_table}_audit`

| Entity Table | Audit Table       |
|--------------|-------------------|
| `users`      | `users_audit`     |
| `posts`      | `posts_audit`     |
| `blog_posts` | `blog_posts_audit`|

### Custom Prefix/Suffix

```php
use DH\Auditor\Provider\Doctrine\Configuration;

// Prefix only: audit_posts
$config = new Configuration([
    'table_prefix' => 'audit_',
    'table_suffix' => '',
]);

// Suffix only: posts_history
$config = new Configuration([
    'table_prefix' => '',
    'table_suffix' => '_history',
]);

// Both: audit_posts_log
$config = new Configuration([
    'table_prefix' => 'audit_',
    'table_suffix' => '_log',
]);
```

## 🔄 Schema Changes

### Adding a New Audited Entity

When you add `#[Auditable]` to a new entity and add it to the configuration:

1. Call `$schemaManager->updateAuditSchema()` to create the new audit table.

### Removing an Audited Entity

> [!NOTE]
> When you remove an entity from the auditing configuration, the audit table is **not** automatically deleted. Historical data is preserved. Drop the table manually if you no longer need it.

### Modifying Entity Fields

> [!TIP]
> Adding or removing fields from an entity requires **no schema changes** to the audit table. Diffs are stored as JSON, so new fields appear in future audits automatically and removed fields simply won't appear in new entries.

## 🗄️ Database-Specific Notes

### MySQL / MariaDB

- Native `JSON` column type is used for `diffs`, `extra_data`, and `blame`
- InnoDB engine is recommended for transactional integrity

### PostgreSQL

- Native `JSON` support
- Indexed columns use PostgreSQL-compatible index names

### SQLite

> [!NOTE]
> SQLite is recommended for development and testing only. It supports JSON operations natively from version 3.38+.

## ⚡ Performance Considerations

1. **Composite indexes** — `(object_id, type)` and `(blame_id, created_at)` cover the most frequent Reader query patterns
2. **JSON storage** — Native JSON types (MySQL, PostgreSQL) provide best query performance on `diffs` and `blame`
3. **Archiving** — Implement a periodic cleanup strategy for high-volume applications using `audit:clean`
4. **Separate database** — Consider storing audits in a dedicated database to avoid impacting application performance

---

## Next Steps

- 🔍 [Querying Audits](../../querying/index.md)
- 🗄️ [Multi-Database Setup](multi-database.md)
- 💡 [Extra Data](../../extra-data.md)
