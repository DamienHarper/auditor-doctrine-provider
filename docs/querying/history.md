---
description: "Read the history of a business object across audit tables with #[AuditRoot], the audit_root index and createRootQuery(), and the last change of each field."
---

# Histories

> **Read the history of a business object across several audit tables, and the last change of each field**

The building blocks (`#[AuditRoot]`, `HistoryGrouper`, `ChangeExtractor`, `BlameOrigin`) are described in the
[auditor documentation](https://damienharper.github.io/auditor-docs/auditor/history). This page covers what the
Doctrine provider adds: the audit root index, the history of a root, and field-level queries.

## 🌳 Declaring roots

With the `#[AuditRoot]` attribute, or the `roots` entity option (both are merged):

```php
use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\AuditRoot;

#[ORM\Entity]
#[Auditable]
#[AuditRoot('property')]
#[AuditRoot('visit.property')]
class VisitReport { /* … */ }
```

```php
$configuration = new Configuration([
    'entities' => [
        App\Entity\VisitReport::class => ['roots' => ['property', 'visit.property']],
    ],
]);
```

A path is a list of **single-valued** association properties (`ManyToOne`, `OneToOne`, owning or inverse side)
separated by dots. A collection in a path is an error, raised at the first flush of the entity. Roots declared on a
parent class are inherited.

For roots that a path cannot express (a polymorphic owner stored as a class and an id, a root picked by business
rules), give the provider a `root_resolver`: a callable that receives an audited entity and returns its extra roots,
keyed by a name of your choice, written as the root path of the index rows (null roots are skipped):

```php
$configuration->setRootResolver(static fn (object $entity): array => match (true) {
    $entity instanceof Attachment => ['subject' => $entity->getSubject()],
    default => [],
});
```

## 🗂️ The audit root index

When at least one entity has roots, `audit:schema:update` creates one more table per storage, `audit_root` (with the
`table_prefix`), which indexes each audit entry under its roots:

| Column | Content |
|---|---|
| `root_class` | FQCN of the root, the root class of its inheritance hierarchy |
| `root_id` | identifier of the root |
| `root_path` | path (or resolver key) that led to the root |
| `audit_table`, `object_id`, `transaction_id` | the audit entry |
| `created_at` | date of the audit entry |

Rows are written in the same database transaction as the audit entries (batched with them when `batch_size` is set).
The roots are resolved during the flush:

- when the first association of a path changes, the entry is indexed under the **old and the new** root: moving a
  visit report to another property shows in both histories;
- the owner of an inverse `OneToOne` not set in memory is looked up among the entities being inserted or updated;
- removed entities are indexed while they still know their roots, so their history stays in the history of the root.

### Rebuilding the index

```bash
php bin/console audit:roots:rebuild                     # every entity with roots
php bin/console audit:roots:rebuild --entity='App\Entity\VisitReport' --dry-run
```

The command replays the diffs of the existing schema v2 entries: the first segment of a path is read from the diffs
of the entry's entity, the next ones from the audit history of the entities the path goes through, as they were when
the entry was written. It writes the missing rows of the paths declared with `#[AuditRoot]` or `roots`, and never
deletes a row: it can be run again safely, and rows you write yourself (for entries whose diffs do not hold the
association, such as imported or legacy entries) are kept. Roots returned by a `root_resolver` cannot be replayed.
Run it after declaring a new root, or after migrating audit tables to schema v2
(`audit:schema:migrate --force --convert-all`): entries in schema v1 or without a transaction id
are skipped and counted. The command fails when an `--entity` is not audited, when it already runs, or when the diffs
of an entry cannot be read; in the last case the rows of the other entries are still written. Rows that no longer match the configuration (a removed path) are not cleaned up: delete
them with SQL if needed.

Index rows go with their entries: the retention pruning (`audit:clean` and the scheduled retention) deletes
the rows whose entry it deleted, and a rolled-back transaction deletes its rows from a remote storage along with its
entries.

## 📜 The history of a root

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;

$reader = new Reader($provider);
$query = $reader->createRootQuery(Property::class, 42);

$page = $query->fetch(20);              // RootPage
foreach ($page->group() as $item) {     // list<HistoryItem>, one per transaction
    // $item->username, $item->origin, $item->getChanges()…
}

$next = $query->fetch(20, after: $page->lastCursor);
$newer = $query->fetch(20, since: $page->firstCursor);   // entries written since the page was read
```

The query reads the entries of the root itself and those indexed under it, in every audit table concerned, and
merges them: most recent first, then by transaction id, entity and id. Each `Entry` has `$entry->entity` (FQCN) and
`$entry->rootPath` (null for the root itself).

Pagination uses opaque cursors (keyset pagination), so a page never shifts when entries are written in the
meantime. `fetch()` extends a page past its size until the transaction of its last entry is complete, so that an
action is never split between two pages: pass the `HistoryGrouper` (or key callable) you display the page with to
keep its groups whole, or `false` to cut the page at its size exactly. `iterate()` yields every entry, keyed by
cursor, reading each table by batches; `count()` counts them.

`fetchTransactions()` counts a page in transactions instead: every page but the last holds exactly that many actions,
each one whole, whatever its number of entries. `countTransactions()` counts the transactions of the history, once
each even when they wrote in several audit tables (twice when these tables are on two connections).

```php
$page = $query->fetchTransactions(20);                        // 20 actions
$next = $query->fetchTransactions(20, after: $page->lastCursor);
$total = $query->countTransactions();
```

A cursor that is not one (edited by hand in a query string, for instance) makes `fetch()` and `iterate()` throw an
`InvalidArgumentException`. `RootQuery::isCursor($cursor)` tells it beforehand, to show the first page instead:

```php
$after = $request->query->getString('after');
$page = $query->fetch(20, after: RootQuery::isCursor($after) ? $after : null);
```

### Options

| Option | Type | Default | Description |
|---|---|---|---|
| `include` | `?list<string>` | `null` | FQCNs of the entities to read (all by default) |
| `paths` | `list<string>` | `[]` | Root paths to keep (all by default). Leaves the entries of the root itself out |
| `self` | `bool` | `true` | Read the entries of the root itself |
| `type` | `string\|array` | `null` | Operation types (`insert`, `update`…) |
| `blame_id` | `int\|string\|array` | `null` | Authors |
| `changed_field` | `string\|list<string>` | `null` | Entries that changed one of the fields (native JSON needed) |
| `blame_origin` | `string\|BlameOrigin\|array` | `null` | `http`, `console`, `messenger`, `system` (native JSON needed) |

An entity in `include` that is not audited, or a path in `paths` that no entity declares (when no `root_resolver` is
set), throws an `InvalidArgumentException`, as does a root class that no auditing entity manager maps.

Only the `view` role of the **root** is checked: reading the history of a root gives access to the entries of the
entities indexed under it. Use the `read_scope` option to restrict them further.

## 🔎 Field-level queries

```php
// last change of each field, or the creation (or snapshot) for a field never changed since
$changes = $reader->getLastChanges(Property::class, 42, ['price', 'status']);
$changes['price']?->getValue('price');  // value of the field: after the change, or at the creation (deep diff tree for a JSON field)
$changes['price']?->getLabel('price');  // its label (#[DiffLabel], association), if any
$changes['price']?->entry->username;    // who, when: the Entry of the change
$changes['price']?->entry->createdAt;

// entries that changed a field, of one object or of all objects of a class
$entries = $reader->getFieldHistory(Property::class, 42, 'price')->execute();
$entries = $reader->getFieldHistory(Property::class, null, 'price')->execute();
```

`getLastChanges()` returns `array<string, ?Change>` (null when nothing was audited). For a field never changed, the
change is the creation of the object, which holds the requested fields: read the value with `getValue($field)` rather
than `new`. For a JSON field changed since, `getValue()` returns the deep diff tree of the last change, not the whole
value (`ChangeKind::Json`). It reads the entries that
changed one of the fields when the platform has native JSON support, and scans the history of the object otherwise.
`getFieldHistory()` returns a `Query` with a `ChangedFieldFilter`, which needs native JSON support.

The `changed_field` option of `createQuery()` also takes a list of fields (entries that changed any of them), and
`blame_origin` keeps the entries of some origins (`BlameOriginFilter`).

## 🧱 Read scope

`read_scope` is a callable applied to every query the reader creates, including each table of a root query. It
receives the query and the FQCN of the entity it reads, and can add filters, for instance to keep the entries of the
current tenant (a `RootFilter` built by hand takes the root class of the inheritance hierarchy):

```php
$configuration->setReadScope(static function (Query $query, string $entity) use ($tenant): void {
    $query->addFilter(new JsonFilter('extra_data', 'tenant', $tenant->getId()));
});
```

## 🏷️ Entity labels

`entity_label_resolver` is a callable (see `EntityLabelResolverInterface`) that builds the label of an entity
written in the `source` and `target` blocks of the diffs, and in association values. Return null to keep the
default label (the string representation of the entity, otherwise `Class#id`); a `Stringable` is accepted. It runs
during the flush and must not flush: an exception it throws aborts the flush and rolls the transaction back.

## Related

- [Querying](index.md)
- [Filters](filters.md)
- [Configuration](../providers/doctrine/configuration.md)
