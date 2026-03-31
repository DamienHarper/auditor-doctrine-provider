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

| Property         | Type                   | Description                                                          |
|------------------|------------------------|----------------------------------------------------------------------|
| `$id`            | `int`                  | Auto-increment primary key of the audit entry                        |
| `$schemaVersion` | `int`                  | Diffs format version: `1` (legacy) or `2` (current). Defaults to `1`.|
| `$type`          | `string`               | Action type: `insert`, `update`, `remove`, `associate`, `dissociate` |
| `$objectId`      | `string`               | Primary key of the audited entity                                    |
| `$discriminator` | `string\|null`         | Entity class (used in inheritance hierarchies)                       |
| `$transactionId` | `string\|null`         | ULID grouping changes from the same flush batch                      |
| `$extraData`     | `array\|null`          | Already-decoded extra data (do **not** call `json_decode()`)         |
| `$userId`        | `int\|string\|null`    | Identifier of the user who made the change (`blame_id`)              |
| `$blame`         | `array\|null`          | Decoded blame context: `username`, `user_fqdn`, `user_firewall`, `ip`|
| `$username`      | `string\|null`         | Convenience shortcut for `$blame['username']`                        |
| `$userFqdn`      | `string\|null`         | Convenience shortcut for `$blame['user_fqdn']`                       |
| `$userFirewall`  | `string\|null`         | Convenience shortcut for `$blame['user_firewall']`                   |
| `$ip`            | `string\|null`         | Convenience shortcut for `$blame['ip']`                              |
| `$createdAt`     | `\DateTimeImmutable`   | Timestamp of when the audit entry was created                        |

> [!IMPORTANT]
> - `$entry->type` is a plain `string` (e.g. `'insert'`, `'update'`), not an enum.
> - `$entry->extraData` is **already decoded** — it returns `?array`. Never call `json_decode()` on it.

## 🔍 Reading Diffs

Use `getDiffs()` to access the field-level change data:

```php
$audits = $reader->createQuery(Post::class, ['object_id' => 42])->execute();

foreach ($audits as $entry) {
    // getDiffs() returns the 'changes' sub-array for schema v2 entries
    // and the raw diff array for legacy schema v1 entries
    foreach ($entry->getDiffs() as $field => $change) {
        $old = $change['old'] ?? '(null)';
        $new = $change['new'] ?? '(null)';
        echo "  $field: $old → $new\n";
    }
}
```

### Diff Structure (schema version 2)

All entry types use the same unified envelope. `getDiffs()` returns the `changes` sub-array directly.

**`insert`** — `old` is always `null`:
```json
{
    "title":   { "old": null, "new": "Hello World" },
    "content": { "old": null, "new": "My first post." }
}
```

**`update`**:
```json
{
    "title": { "old": "Hello World", "new": "Hello auditor!" }
}
```

**`remove`** — `new` is always `null`; full snapshot stored in `old`:
```json
{
    "title":   { "old": "Hello auditor!", "new": null },
    "content": { "old": "My first post.", "new": null }
}
```

**`associate` / `dissociate`** — no `changes` key; `getDiffs()` returns the full envelope:
```json
{
    "source":         { "id": "1", "class": "App\\Entity\\Post", "label": "Post #1", "table": "posts", "field": "tags" },
    "target":         { "id": "5", "class": "App\\Entity\\Tag",  "label": "php",     "table": "tag",   "field": "posts" },
    "is_owning_side": true,
    "join_table":     "post__tag"
}
```

### Source and Target Metadata

For schema v2 entries, use `getDiffSource()` and `getDiffTarget()`:

```php
// getDiffSource(): entity metadata at the time of the operation (all types)
$source = $entry->getDiffSource();
// ['class' => 'App\Entity\Post', 'id' => '42', 'label' => 'Hello World', 'table' => 'posts']

// getDiffTarget(): second entity in associate/dissociate entries
$target = $entry->getDiffTarget();
// ['class' => 'App\Entity\Tag', 'field' => 'posts', 'id' => '5', 'label' => 'php', 'table' => 'tag']
```

Both return `null` for legacy schema v1 entries.

### Legacy Diff Structure (schema version 1)

`getDiffs()` returns the raw decoded array as-is (minus the internal `@source` key):

**`insert`**:
```json
{
    "@source": { "id": 42, "class": "App\\Entity\\Post", "label": "Hello World", "table": "posts" },
    "title":   { "new": "Hello World" },
    "content": { "new": "My first post." }
}
```

**`update`**:
```json
{
    "title": { "old": "Hello World", "new": "Hello auditor!" }
}
```

**`remove`**:
```json
{
    "id":    42,
    "class": "App\\Entity\\Post",
    "label": "Hello auditor!",
    "table": "posts"
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
    $source = $entry->getDiffSource();

    printf(
        "[%s] %s #%s — by %s (%s) from %s at %s\n",
        $entry->type,
        $source['class'] ?? $entity,
        $entry->objectId,
        $entry->username ?? 'anonymous',
        $entry->userId ?? '-',
        $entry->ip ?? '-',
        $entry->createdAt->format('Y-m-d H:i:s')
    );

    foreach ($entry->getDiffs() as $field => $change) {
        if (!isset($change['old'], $change['new'])) {
            continue;
        }
        printf("  %s: %s → %s\n", $field, $change['old'] ?? '(null)', $change['new'] ?? '(null)');
    }
}
```

---

## Related

- 🔍 [Querying Audits](index.md)
- 🔧 [Filters Reference](filters.md)
- 💡 [Extra Data](../extra-data.md)
