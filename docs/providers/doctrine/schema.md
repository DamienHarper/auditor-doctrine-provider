---
description: "Audit table structure, diffs format and writing audit rows yourself; create and update audit tables with SchemaManager and the audit:schema:update, audit:schema:migrate, audit:clean, audit:export and audit:snapshot commands."
---

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
    schema_version   SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    type             VARCHAR(11)  NOT NULL,
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
    INDEX transaction_id_idx  (transaction_id),
    INDEX blame_id_created_at_idx (blame_id, created_at),
    INDEX created_at_idx      (created_at)
);
```

The SQL is illustrative: the actual index names are generated from a hash of the table name
(`type_<md5>_idx`…), and the column types depend on the platform (JSON on MySQL / MariaDB, JSONB on PostgreSQL,
TEXT on SQLite).

### Column Details

| Column           | Type                | Description                                                       |
|------------------|---------------------|-------------------------------------------------------------------|
| `id`             | BIGINT (PK)         | Auto-increment primary key                                        |
| `schema_version` | SMALLINT            | Diffs format version: `1` (legacy) or `2` (current)              |
| `type`           | VARCHAR(11)         | Action: insert, update, remove, associate, dissociate             |
| `object_id`      | VARCHAR(255)        | The primary key of the audited entity                             |
| `discriminator`  | VARCHAR(255)        | Entity class (used in inheritance hierarchies)                    |
| `transaction_id` | CHAR(26)            | ULID grouping changes from the same flush batch                   |
| `diffs`          | JSON                | JSON-encoded change data (see format below)                       |
| `extra_data`     | JSON                | Custom extra data (populated via `LifecycleEvent` listener)       |
| `blame_id`       | VARCHAR(255)        | User identifier who made the change                               |
| `blame`          | JSON                | Blame: `username`, `user_fqdn`, `user_firewall`, `ip`, `impersonator` when the user is impersonated, and `via` when a blame context is set |
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
  - **REMOVE**: empty by default; with `full_snapshot_on_remove`, every audited field with its `old` value and a `null` `new` (a generated identifier, reset by Doctrine on deletion, is left out: the `source` block holds it), and every owning-side to-one association with the summary of its target as `old`
- `snapshot` — only on entries written by [`audit:snapshot`](#auditsnapshot): an `insert` entry with
  `"snapshot": true` records the state of an entity that existed before auditing started, not its creation
  (`Entry::isSnapshot()` reads it)
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

### Writing audit rows yourself

The provider writes audit rows on flush only. An application that writes rows itself (for example to import the
history kept by another tool) must follow the format above, or the `Reader` and the viewer misread the rows without
any error:

| Column | What to write |
|---|---|
| `schema_version` | `2`, always written explicitly: do not rely on the column default, which can still be `1` on a table left half-migrated or completed by hand; a row read as `1` has its diffs read as the legacy format |
| `type` | `insert`, `update`, `remove`, `associate` or `dissociate` |
| `object_id`, `discriminator` | the identifier of the entity as a string; its class in `discriminator` for an entity of an inheritance hierarchy, `NULL` otherwise |
| `transaction_id` | a ULID (26 characters, e.g. `(string) new Ulid()` with `symfony/uid`), the same for every row written by one flush (one transaction), across tables: the viewer and `Query::transactions()` group the entries by it |
| `diffs` | the envelope `{"source": {…}, "changes": {"field": {"old": …, "new": …}}}`, or the association structure; `source` holds `id`, `class`, `label` and `table`; plus `deep_diffs` (the fields whose change is a JSON deep diff) when relevant, without which the deep diffs are inferred from their shape |
| `blame_id`, `blame` | `NULL` and `NULL` when nobody is known; otherwise `blame` holds the four keys `username`, `user_fqdn`, `user_firewall` and `ip`, each set to `null` when unknown, plus `impersonator` and `via` when relevant |
| `extra_data` | a JSON object, or `NULL` |
| `created_at` | the date of the change, `Y-m-d H:i:s`, in the timezone of the configuration (`timezone`, UTC by default) |

- `diffs`, `blame` and `extra_data` are `JSONB` on PostgreSQL: cast the values (`::jsonb`) in a hand-written
  `INSERT`.
- Rows written by hand go around the `LifecycleEvent` listeners of the application (extra data, rewriting of the
  diffs): apply their effect yourself.
- Rows of entities that declare an [`#[AuditRoot]`](../../querying/history.md) are not indexed in `audit_root`: run
  [`audit:roots:rebuild`](#auditrootsrebuild) after the import, or they are missing from the histories.

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

Six console commands are available.

#### `audit:schema:update`

Creates new audit tables and updates existing ones to match the current schema.

```bash
# Preview the SQL that would be executed
php bin/console audit:schema:update --dump-sql

# Execute the changes
php bin/console audit:schema:update --force

# Both: show SQL and execute
php bin/console audit:schema:update --dump-sql --force
```

#### `audit:schema:migrate`

Migrates existing audit tables from schema version 1 to schema version 2.

```bash
# Preview the SQL that would be executed (dry-run)
php bin/console audit:schema:migrate --dump-sql --convert-all

# Execute the migration and convert the existing rows (recommended)
php bin/console audit:schema:migrate --force --convert-all

# Convert a sample only, to estimate the duration (keeps the legacy columns, resumable)
php bin/console audit:schema:migrate --force --convert-all --limit=10000
```

> [!CAUTION]
> The migration drops the legacy columns and cannot be undone: back up the audit tables first. `transaction_hash` is
> only dropped once converted (`--convert-transaction-hash` or `--convert-all`), or with `--drop-transaction-hash`,
> which drops it without converting it: legacy rows then lose their transaction grouping. Follow the
> [upgrade guide](../../upgrade/v2.md#-upgrade-steps) for the complete procedure.

> [!NOTE]
> Use `audit:schema:migrate` when upgrading an existing installation. New installations created
> with `audit:schema:update` already use schema version 2.

> [!NOTE]
> `--convert-diffs` rewrites existing diffs rows in PHP (batches of 500). This can take a while
> on large tables. Without this flag, legacy rows remain readable via `Entry::getDiffs()` since
> the `Entry` model handles both schema versions transparently.

#### `audit:clean`

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

Entities with a retention policy (`#[Auditable(maxAge: …, maxEntries: …)]`, or the `max_age` / `max_entries` entity
options) apply it: `maxAge` replaces the global cutoff, and `maxEntries` also deletes everything but the N most recent
entries. See [Retention Policy](attributes.md#retention-policy).

#### `audit:export`

Exports audit entries as NDJSON (default, recommended for large exports), JSON or CSV, streamed to the standard
output or to a file.

```bash
# Every auditable entity, NDJSON on stdout
php bin/console audit:export

# One entity, as CSV, for a period
php bin/console audit:export App\\Entity\\Post --format=csv --from=2026-01-01 --to=2026-03-31 --output=posts.csv

# One object, or one user, without the user and IP fields
php bin/console audit:export App\\Entity\\Post --id=42 --anonymize
php bin/console audit:export --blame-id=7
```

Each line or row is an `Entry::toArray()`: the same columns for legacy and schema v2 entries.

#### `audit:roots:rebuild`

Indexes the existing audit entries under their audit roots (`#[AuditRoot]`, `roots` entity option), in the
`audit_root` table: after declaring a new root, or after migrating audit tables to schema v2.

```bash
php bin/console audit:roots:rebuild
php bin/console audit:roots:rebuild --entity='App\Entity\Comment' --dry-run
```

| Option      | Description                                                    |
|-------------|----------------------------------------------------------------|
| `--entity`  | Entities whose entries are indexed (repeatable), all by default |
| `--dry-run` | Count the missing index rows, write nothing                    |

Only missing rows are written and no row is deleted, so the command can be run again and rows written by hand are
kept. See
[Histories](../../querying/history.md#rebuilding-the-index).

#### `audit:snapshot`

Records the current state of existing entities, so that their history does not start at their first change
when auditing is enabled on a database that already holds data. Each entity gets one `insert` entry flagged
with `"snapshot": true`, holding the current value of every audited field (`old: null`).

```bash
# Snapshot some entities
php bin/console audit:snapshot 'App\Entity\Post' 'App\Entity\Author'

# Snapshot every audited entity, 1,000 rows per database transaction
php bin/console audit:snapshot --all --batch-size=1000

# Count what would be written
php bin/console audit:snapshot --all --dry-run
```

| Option         | Short | Description                                                                  |
|----------------|-------|------------------------------------------------------------------------------|
| `--all`        | `-a`  | Snapshot every audited entity (instead of the entities given as arguments)   |
| `--batch-size` | `-b`  | Entities read and written per database transaction (default `500`)           |
| `--force`      | `-f`  | Also snapshot entities that already have an audit history                    |
| `--dry-run`    |       | Count the entities to snapshot, write nothing                                |

- **Idempotent**: an entity that already has at least one audit entry is skipped, so the command can be
  run again (e.g. after an interruption). `--force` snapshots it anyway: a manual snapshot of its current state.
- **Batches**: entities are read in identifier order (keyset pagination), each batch is written in its own
  database transaction and its entries share one `transaction_id`. The entity manager is cleared between batches.
- **Content**: the same fields as an insert: ignored columns and generated identifiers are left out,
  owning-side to-one associations are summarized, JSON fields are deep diffs. To-many associations are not
  snapshotted.
- **Context**: `created_at` is the date of the snapshot, the blame is the console user (`blame_id` is `null`
  without a user provider). Doctrine filters that are enabled apply: rows they hide (e.g. soft-deleted) are
  not snapshotted.
- **Inheritance**: an entity whose parent class is also selected is read through its parent; each row is
  written to the audit table of its own class.

The same logic is available in PHP through `DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter`:

```php
use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;

$result = new Snapshotter($provider)->snapshot(Post::class, batchSize: 500);
$result->read;        // entities read
$result->snapshotted; // entries written
$result->skipped();   // entities that already had an audit history
```

> [!NOTE]
> These commands use Symfony's Lock component to prevent concurrent execution.

#### Registering Commands (Standalone)

When not using auditor-bundle, register the commands manually:

```php
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\ExportAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\RebuildRootsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\SnapshotCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\UpdateSchemaCommand;
use Symfony\Component\Console\Application;

$application = new Application();

foreach ([
    new UpdateSchemaCommand(),
    new MigrateSchemaCommand(),
    new CleanAuditLogsCommand(),
    new SnapshotCommand(),
    new ExportAuditLogsCommand(),
    new RebuildRootsCommand(),
] as $command) {
    $application->addCommand($command->setAuditor($auditor));
}

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
    echo sprintf('Progress: %d / %d', $progress['current'], $progress['total']);
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
- InnoDB does not compress anything by default. On **MariaDB**, enable the [`compression`](configuration.md#-compression)
  option to store `diffs` and `extra_data` as compressed columns (`JSON COMPRESSED`).
- **MySQL** has no column compression, so the `compression` option has no effect there. If disk space matters more
  than speed, InnoDB page compression can be enabled manually on audit tables (requires `innodb_file_per_table` and a
  file system supporting hole punching, e.g. ext4 or XFS):

  ```sql
  ALTER TABLE posts_audit COMPRESSION='zlib';
  OPTIMIZE TABLE posts_audit; -- compresses existing rows
  ```

  Doctrine does not manage table compression options: this is neither reported nor reverted by schema commands.

### PostgreSQL

- Native `JSONB` column type is used for `diffs`, `extra_data` and `blame`
- Large `JSONB` values are compressed automatically (TOAST): the `compression` option is not needed and has no effect
- Audit tables created with `JSON` columns by previous versions are converted to `JSONB` by `audit:schema:update` (or by your next migration if you manage audit tables with Doctrine Migrations)
- Indexed columns use PostgreSQL-compatible index names

### SQLite

> [!NOTE]
> SQLite is recommended for development and testing only. It supports JSON operations natively from version 3.38+.

## ⚡ Performance Considerations

1. **Few indexes** — `(object_id, type)` and `(blame_id, created_at)` cover the most frequent Reader query patterns, and every
   other secondary index slows each audit `INSERT` down: there is none on `object_id`, `blame_id` or `discriminator`
   alone (5 secondary indexes instead of 8 before 2.0: audit `INSERT`s about 18 % faster on MySQL, indexes 30 % smaller)
2. **JSON storage** — Native JSON types (MySQL, PostgreSQL) provide best query performance on `diffs` and `blame`
3. **Archiving** — Implement a periodic cleanup strategy for high-volume applications using `audit:clean`
4. **Separate database** — Consider storing audits in a dedicated database to avoid impacting application performance

---

## Next Steps

- 🔍 [Querying Audits](../../querying/index.md)
- 🗄️ [Multi-Database Setup](multi-database.md)
- 💡 [Extra Data](../../extra-data.md)
