# Extra Data

> **Attach custom contextual information to audit entries**

The `extra_data` field allows you to store arbitrary JSON alongside each audit entry. It is populated via the auditor's `LifecycleEvent` system.

## 🔍 Overview

Every audit entry has an `extra_data` JSON column that is `null` by default. You can populate it in two ways:

1. **`extra_data_provider`** — A global callable on the auditor `Configuration` that returns data for every audit entry
2. **`LifecycleEvent` listener** — A Symfony event listener that inspects the entity and populates `extra_data` before persistence

## 🚀 Option 1: Extra Data Provider (Global)

The simplest approach is to configure a global `extra_data_provider` on the auditor `Configuration`:

```php
<?php

use DH\Auditor\Configuration;

$configuration = new Configuration([
    'enabled'  => true,
    'timezone' => 'UTC',
]);

$configuration->setExtraDataProvider(function (): ?array {
    // Return null to skip extra_data for this entry
    return [
        'app_version' => '2.1.0',
        'request_id'  => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ];
});
```

> [!NOTE]
> The `extra_data_provider` is called for every audit entry. If it returns `null`, `extra_data` is stored as `null`.

## 🎧 Option 2: LifecycleEvent Listener (Per-Entry)

For more control — particularly when you need access to the audited entity — register a listener on `LifecycleEvent`:

```php
<?php

use DH\Auditor\Event\LifecycleEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

$eventDispatcher = new EventDispatcher();

$eventDispatcher->addListener(LifecycleEvent::class, function (LifecycleEvent $event): void {
    $payload = $event->getPayload();
    $entity  = $event->getEntity();  // The audited Doctrine entity object

    // Only enrich specific entities
    if (!$entity instanceof App\Entity\Order) {
        return;
    }

    $extra = [
        'status'       => $entity->getStatus(),
        'total_amount' => $entity->getTotalAmount(),
        'currency'     => $entity->getCurrency(),
    ];

    $payload['extra_data'] = json_encode($extra, JSON_THROW_ON_ERROR);
    $event->setPayload($payload);
});

$auditor = new Auditor($configuration, $eventDispatcher);
```

> [!IMPORTANT]
> The `LifecycleEvent` listener receives both the audit `$payload` and the `$entity` (the actual Doctrine entity instance). This gives you access to all entity state at the moment of the change.

## 🔀 Priority: Listener vs. Provider

When both a `LifecycleEvent` listener and an `extra_data_provider` are configured:

- The **provider** value is computed first and stored in `payload['extra_data']`
- A **listener** can then **override** `payload['extra_data']` with a custom value

This allows the provider to set a sensible default and let listeners refine it for specific entities.

## 💡 Common Use Cases

### Audit Reason / Comment

```php
$eventDispatcher->addListener(LifecycleEvent::class, function (LifecycleEvent $event): void {
    $payload = $event->getPayload();

    // Read a reason injected by the application layer
    $reason = AuditContext::getReason();

    if (null !== $reason) {
        $payload['extra_data'] = json_encode(['reason' => $reason], JSON_THROW_ON_ERROR);
        $event->setPayload($payload);
    }
});
```

### Soft Delete Flag

```php
$eventDispatcher->addListener(LifecycleEvent::class, function (LifecycleEvent $event): void {
    $payload = $event->getPayload();
    $entity  = $event->getEntity();

    if ('remove' !== $payload['type']) {
        return;
    }

    if (method_exists($entity, 'isDeleted') && $entity->isDeleted()) {
        $payload['extra_data'] = json_encode(['soft_delete' => true], JSON_THROW_ON_ERROR);
        $event->setPayload($payload);
    }
});
```

### Request Metadata

```php
$eventDispatcher->addListener(LifecycleEvent::class, function (LifecycleEvent $event): void {
    $payload = $event->getPayload();

    $payload['extra_data'] = json_encode([
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ], JSON_THROW_ON_ERROR);

    $event->setPayload($payload);
});
```

## 🔍 Reading Extra Data

`$entry->extraData` is **already decoded** — it returns `?array`. Do not call `json_decode()` on it:

```php
<?php

$entries = $reader->createQuery(App\Entity\Order::class)->execute();

foreach ($entries as $entry) {
    // $entry->extraData is ?array — already decoded
    if (null !== $entry->extraData) {
        echo 'Status: '       . ($entry->extraData['status'] ?? '-') . "\n";
        echo 'Total Amount: ' . ($entry->extraData['total_amount'] ?? '-') . "\n";
    }
}
```

## ✅ Best Practices

1. **Keep extra data small** — It's stored as JSON per entry; avoid large payloads
2. **Use `null` to skip** — Return `null` from the provider when there's nothing to add
3. **Avoid PII unless necessary** — Extra data is as visible as the audit entry itself
4. **Encode consistently** — Always use `json_encode()` with `JSON_THROW_ON_ERROR`

---

## Filtering by Extra Data

You can filter audit entries by `extra_data` content using the `JsonFilter` class. This generates platform-specific SQL for optimal performance.

### Basic Usage

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

$reader = new Reader($provider);
$query = $reader->createQuery(User::class, ['page_size' => null]);

// Filter by exact value
$query->addFilter(new JsonFilter('extra_data', 'department', 'IT'));

// Filter with LIKE pattern
$query->addFilter(new JsonFilter('extra_data', 'department', 'IT%', 'LIKE'));

// Filter by multiple values (IN)
$query->addFilter(new JsonFilter('extra_data', 'status', ['active', 'pending'], 'IN'));

// Filter where value is NULL
$query->addFilter(new JsonFilter('extra_data', 'deleted_by', null, 'IS NULL'));

// Nested JSON path
$query->addFilter(new JsonFilter('extra_data', 'user.role', 'admin'));

$entries = $query->execute();
```

### Supported Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `=` | Exact match (default) | `new JsonFilter('extra_data', 'dept', 'IT')` |
| `!=` or `<>` | Not equal | `new JsonFilter('extra_data', 'dept', 'IT', '!=')` |
| `LIKE` | Pattern matching | `new JsonFilter('extra_data', 'dept', 'IT%', 'LIKE')` |
| `NOT LIKE` | Negative pattern | `new JsonFilter('extra_data', 'dept', '%temp%', 'NOT LIKE')` |
| `IN` | Multiple values | `new JsonFilter('extra_data', 'dept', ['IT', 'HR'], 'IN')` |
| `NOT IN` | Exclude values | `new JsonFilter('extra_data', 'dept', ['IT'], 'NOT IN')` |
| `IS NULL` | Value is null | `new JsonFilter('extra_data', 'dept', null, 'IS NULL')` |
| `IS NOT NULL` | Value exists | `new JsonFilter('extra_data', 'dept', null, 'IS NOT NULL')` |

### Supported Databases

| Database   | Minimum Version | JSON Function Used |
|------------|-----------------|-------------------|
| MySQL      | 5.7.0           | `JSON_UNQUOTE(JSON_EXTRACT())` |
| MariaDB    | 10.2.3          | `JSON_UNQUOTE(JSON_EXTRACT())` |
| PostgreSQL | 9.4.0           | `->>` operator |
| SQLite     | 3.38.0          | `json_extract()` |

### Strict Mode

By default, if the database doesn't support JSON functions, the filter falls back to `LIKE` pattern matching with a warning. To enforce JSON support and throw an exception instead:

```php
$filter = new JsonFilter('extra_data', 'department', 'IT', '=', strict: true);
$query->addFilter($filter);
```

---

## JSON Indexing for Performance

For frequently queried JSON paths, consider adding database indexes to improve performance.

### MySQL 8.0+

```sql
ALTER TABLE user_audit
ADD INDEX idx_extra_department ((
    CAST(extra_data->>'$.department' AS CHAR(50) COLLATE utf8mb4_bin)
));
```

### MariaDB 10.2+

```sql
ALTER TABLE user_audit
ADD COLUMN extra_department VARCHAR(50) AS (JSON_VALUE(extra_data, '$.department')) VIRTUAL,
ADD INDEX idx_extra_department (extra_department);
```

### PostgreSQL

```sql
-- GIN index for general JSON queries
CREATE INDEX idx_extra_data_gin ON user_audit USING GIN (extra_data jsonb_path_ops);

-- B-tree index for specific path (equality queries)
CREATE INDEX idx_extra_department ON user_audit ((extra_data->>'department'));
```

### SQLite

> [!WARNING]
> SQLite does not support indexes on JSON expressions. Consider using a different database or denormalizing frequently queried values into separate columns.

---

## Related

- 🚀 [Quick Start](getting-started/quick-start.md)
- 📦 [Entry Reference](querying/entry.md)
- 🎯 [Filters Reference](querying/filters.md)
- ⚙️ [Configuration Reference](providers/doctrine/configuration.md)
