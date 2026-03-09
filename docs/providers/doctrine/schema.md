# Schema Management

> **Create and manage audit tables in your database**

This guide covers how to create and manage audit tables using the `SchemaManager`.

## 🔍 Overview

For each audited entity, the provider creates a corresponding audit table to store the change history. The `SchemaManager` class handles all schema operations using DBAL's schema introspection API.

The schema can be managed in two ways:

1. **Automatically** — via Doctrine's `postGenerateSchemaTable` event (integrates with `doctrine:schema:update` / Migrations)
2. **Manually** — by calling `SchemaManager::updateAuditSchema()` directly

## 🏗️ Audit Table Structure

Each audit table has the following structure:

```sql
CREATE TABLE posts_audit (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                VARCHAR(10)   NOT NULL,
    object_id           VARCHAR(255)  NOT NULL,
    discriminator       VARCHAR(255)  NULL,
    transaction_hash    VARCHAR(40)   NULL,
    diffs               JSON          NULL,
    extra_data          JSON          NULL,
    blame_id            VARCHAR(255)  NULL,
    blame_user          VARCHAR(255)  NULL,
    blame_user_fqdn     VARCHAR(255)  NULL,
    blame_user_firewall VARCHAR(100)  NULL,
    ip                  VARCHAR(45)   NULL,
    created_at          DATETIME      NOT NULL,

    INDEX type_idx              (type),
    INDEX object_id_idx         (object_id),
    INDEX discriminator_idx     (discriminator),
    INDEX transaction_hash_idx  (transaction_hash),
    INDEX blame_id_idx          (blame_id),
    INDEX created_at_idx        (created_at)
);
```

### Column Details

| Column                | Type          | Description                                                     |
|-----------------------|---------------|-----------------------------------------------------------------|
| `id`                  | BIGINT (PK)   | Auto-increment primary key                                      |
| `type`                | VARCHAR(10)   | Action: insert, update, remove, associate, dissociate           |
| `object_id`           | VARCHAR(255)  | The primary key of the audited entity                           |
| `discriminator`       | VARCHAR(255)  | Entity class (used in inheritance hierarchies)                  |
| `transaction_hash`    | VARCHAR(40)   | Groups changes from the same flush batch                        |
| `diffs`               | JSON          | JSON-encoded change data                                        |
| `extra_data`          | JSON          | Custom extra data (populated via `LifecycleEvent` listener)     |
| `blame_id`            | VARCHAR(255)  | User identifier who made the change                             |
| `blame_user`          | VARCHAR(255)  | Username/display name                                           |
| `blame_user_fqdn`     | VARCHAR(255)  | Full class name of the user object                              |
| `blame_user_firewall` | VARCHAR(100)  | Context/firewall name                                           |
| `ip`                  | VARCHAR(45)   | Client IP address (IPv4 or IPv6)                                |
| `created_at`          | DATETIME      | When the audit entry was created                                |

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

When using auditor-bundle with Symfony, two console commands are available:

```bash
# Update audit schema (create/update audit tables)
php bin/console audit:schema:update

# Clean old audit log entries
php bin/console audit:clean
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

- Native `JSON` column type is used for `diffs` and `extra_data`
- InnoDB engine is recommended for transactional integrity

### PostgreSQL

- Native `JSON` support
- Indexed columns use PostgreSQL-compatible index names

### SQLite

> [!NOTE]
> SQLite is recommended for development and testing only. It supports JSON operations natively from version 3.38+.

## ⚡ Performance Considerations

1. **Indexed columns** — All common query columns (`type`, `object_id`, `transaction_hash`, `blame_id`, `created_at`) are indexed at creation time
2. **JSON storage** — Native JSON types (MySQL, PostgreSQL) provide best query performance on `diffs`
3. **Archiving** — Implement a periodic cleanup strategy for high-volume applications using `audit:clean`
4. **Separate database** — Consider storing audits in a dedicated database to avoid impacting application performance

---

## Next Steps

- 🔍 [Querying Audits](../../querying/index.md)
- 🗄️ [Multi-Database Setup](multi-database.md)
- 💡 [Extra Data](../../extra-data.md)
