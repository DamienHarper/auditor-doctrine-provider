# Entry Reference

> **The audit log entry model**

An `Entry` object represents a single audit log record. It is returned by `Query::execute()` and is part of the `damienharper/auditor` core library.

## 📦 The Entry Class

```php
use DH\Auditor\Model\Entry;
```

`Entry` is a read-only value object hydrated from a database row.

## 📋 Virtual Properties

`Entry` exposes its data through **virtual properties** (not getter methods). Access them directly:

| Property           | Type                   | Description                                                      |
|--------------------|------------------------|------------------------------------------------------------------|
| `$id`              | `int`                  | Auto-increment primary key of the audit entry                    |
| `$type`            | `string`               | Action type: `insert`, `update`, `remove`, `associate`, `dissociate` |
| `$objectId`        | `string`               | Primary key of the audited entity                                |
| `$discriminator`   | `string\|null`         | Entity class (used in inheritance hierarchies)                   |
| `$transactionHash` | `string\|null`         | Hash grouping changes from the same flush batch                  |
| `$diffs`           | `array\|null`          | Already-decoded change data (do **not** call `json_decode()`)    |
| `$extraData`       | `array\|null`          | Already-decoded extra data (do **not** call `json_decode()`)     |
| `$userId`          | `string\|null`         | Identifier of the user who made the change (`blame_id`)          |
| `$username`        | `string\|null`         | Display name of the user (`blame_user`)                          |
| `$userFqdn`        | `string\|null`         | Class name of the user object (`blame_user_fqdn`)                |
| `$userFirewall`    | `string\|null`         | Context/firewall name (`blame_user_firewall`)                    |
| `$ip`              | `string\|null`         | Client IP address                                                |
| `$createdAt`       | `\DateTimeImmutable`   | Timestamp of when the audit entry was created                    |

> [!IMPORTANT]
> - `$entry->type` is a plain `string` (e.g. `'insert'`, `'update'`), not an enum.
> - `$entry->extraData` and `$entry->diffs` are **already decoded** — they return `?array`. Never call `json_decode()` on them.

## 🔍 Reading Diffs

```php
<?php

$audits = $reader->createQuery(Post::class, ['object_id' => 42])->execute();

foreach ($audits as $entry) {
    if ('insert' === $entry->type) {
        // Insert diffs: field => ['new' => value]
        foreach ($entry->diffs ?? [] as $field => $change) {
            if (str_starts_with($field, '@')) {
                continue;
            }
            echo "  Set $field to: " . $change['new'] . "\n";
        }
    }

    if ('update' === $entry->type) {
        // Update diffs: field => ['old' => value, 'new' => value]
        foreach ($entry->diffs ?? [] as $field => $change) {
            $old = $change['old'] ?? '(null)';
            $new = $change['new'] ?? '(null)';
            echo "  $field: $old → $new\n";
        }
    }

    if ('remove' === $entry->type) {
        // Remove diffs: summary of the deleted entity
        echo "  Deleted: " . ($entry->diffs['label'] ?? '-') . " (id=" . ($entry->diffs['id'] ?? '-') . ")\n";
    }
}
```

### Diff Structure by Action Type

**`insert`**
```json
{
  "@source": { "id": 42, "class": "App\\Entity\\Post", "label": "Hello World", "table": "posts" },
  "title":   { "new": "Hello World" },
  "content": { "new": "My first post." }
}
```

**`update`**
```json
{
  "title": { "old": "Hello World", "new": "Hello auditor!" }
}
```

**`remove`**
```json
{
  "id":    42,
  "class": "App\\Entity\\Post",
  "label": "Hello auditor!",
  "table": "posts"
}
```

**`associate` / `dissociate`**
```json
{
  "source": { "id": 1, "class": "App\\Entity\\Post", "label": "Post #1", "table": "posts", "field": "tags" },
  "target": { "id": 5, "class": "App\\Entity\\Tag", "table": "post_tag" }
}
```

## 💡 Reading Extra Data

```php
// $entry->extraData is already decoded — no json_decode() needed
if (null !== $entry->extraData) {
    echo 'Reason: ' . ($entry->extraData['reason'] ?? 'unknown') . "\n";
}
```

## 📅 Timezone Handling

The `$createdAt` property is a `\DateTimeImmutable` object. Its timezone is set to the timezone configured in the auditor `Configuration`:

```php
$configuration = new Configuration(['timezone' => 'Europe/Paris']);

// ...

foreach ($entries as $entry) {
    $dt = $entry->createdAt;
    echo $dt->format('Y-m-d H:i:s T'); // e.g., 2024-06-15 14:30:00 CEST
}
```

## 📝 Full Example

```php
<?php

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;

$reader = new Reader($provider);
$entries = $reader->createQuery(App\Entity\Post::class, [
    'object_id' => 42,
    'page'      => 1,
    'page_size' => 10,
])->execute();

foreach ($entries as $entry) {
    printf(
        "[%s] Post #%s — by %s (%s) from %s at %s\n",
        $entry->type,
        $entry->objectId,
        $entry->username ?? 'anonymous',
        $entry->userId ?? '-',
        $entry->ip ?? '-',
        $entry->createdAt->format('Y-m-d H:i:s')
    );

    foreach ($entry->diffs ?? [] as $field => $change) {
        if (str_starts_with($field, '@')) {
            continue;
        }
        $old = $change['old'] ?? null;
        $new = $change['new'] ?? null;
        printf("  %s: %s → %s\n", $field, $old ?? '(null)', $new ?? '(null)');
    }
}
```

---

## Related

- 🔍 [Querying Audits](index.md)
- 🔧 [Filters Reference](filters.md)
- 💡 [Extra Data](../extra-data.md)
