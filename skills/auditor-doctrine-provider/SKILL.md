---
name: auditor-doctrine-provider
description: "Use when auditing Doctrine ORM entities with damienharper/auditor-doctrine-provider 2.x (the Doctrine provider of the auditor audit-log library): standalone setup and configuration, #[Auditable]/#[Ignore]/#[Security] attributes, audit tables and their schema, migrating audit tables from schema v1 to v2, and reading audits with the Reader, Query and filters. Targets the 2.x line (auditor 5.x); 1.x differs."
---

# auditor-doctrine-provider (2.x)

Doctrine ORM provider of [`damienharper/auditor`](https://github.com/DamienHarper/auditor). On `flush()`, it turns the
UnitOfWork changesets of audited entities into audit entries (insert, update, remove, associate, dissociate) and
writes them to one audit table per entity (`posts` → `posts_audit`), **in the same database transaction**, just before
the `COMMIT`. It also reads them back (`Reader`, `Query`, filters).

Namespace: `DH\Auditor\Provider\Doctrine`; core classes (`Auditor`, `Configuration`, attributes, `Entry`): `DH\Auditor`.

## Symfony application? Use the bundle

In a Symfony app, install `damienharper/auditor-bundle` (8.x for provider 2.x) instead of wiring the provider by hand:
it creates the `Auditor`, the provider, the services, the DBAL middleware, the console commands and a viewer from YAML
configuration. Read the bundle docs; this skill then still applies to attributes, audit tables and the `Reader`.

## Versions

| Provider | auditor (core) | PHP | doctrine/dbal | doctrine/orm | Symfony components |
|---|---|---|---|---|---|
| 2.x (current) | ^5.0 | >= 8.4 | ^4.6 | ^3.7 | ^8.1 (`symfony/cache`, `symfony/lock`) |
| 1.x (bug and security fixes) | >= 4.1 | >= 8.4 | >= 4.3 | >= 3.2 | |

2.x writes **schema v2** audit tables; tables created by 1.x must be migrated (see "Migrating from 1.x").
The console commands extend `Symfony\Component\Console\Command\Command`: outside Symfony, `composer require
symfony/console` to run them.

## Standalone setup

```php
use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\User\User;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

// 1. The DBAL connection of every auditing entity manager MUST have the AuditorMiddleware,
//    set before the connection is created: it writes the audits at COMMIT. Without it, nothing is audited.
$dbalConfiguration = new DbalConfiguration();
$dbalConfiguration->setMiddlewares([new AuditorMiddleware()]);
$connection = DriverManager::getConnection($connectionParams, $dbalConfiguration);
// ... build $entityManager on $connection

// 2. Core auditor: blame (user, IP, firewall) comes from callables
$auditorConfiguration = new AuditorConfiguration(['timezone' => 'UTC']);
$auditorConfiguration->setUserProvider(fn (): ?User => new User('42', 'alice'));          // id, username
$auditorConfiguration->setSecurityProvider(fn (): array => [$clientIp, $firewallName]);   // [ip, firewall]
$auditor = new Auditor($auditorConfiguration, new EventDispatcher());

// 3. Provider
$provider = new DoctrineProvider(new Configuration([
    'table_suffix' => '_audit',
    'entities' => [App\Entity\Post::class => ['enabled' => true]], // merged with #[Auditable] entities
]));
$provider->registerAuditingService(new AuditingService('default', $entityManager)); // attaches the Doctrine listeners
$provider->registerStorageService(new StorageService('default', $entityManager));   // where audit rows are written
$auditor->registerProvider($provider);

// 4. Create / update the audit tables
(new SchemaManager($provider))->updateAuditSchema();
```

- Same entity manager for both services = single database. A different `StorageService` entity manager = audits in
  another database (see "Several databases").
- `registerAuditingService()` attaches the listeners immediately; a DI container can call
  `$provider->disableListenerAttachment()` first and declare `$provider->getDoctrineSubscriber()` (onFlush, postFlush)
  and `new CreateSchemaListener($provider)` (postGenerateSchemaTable) itself.
- Long-running workers (Messenger): call `$provider->reset()` between messages (it implements `ResetInterface`).

### Provider options (`DH\Auditor\Provider\Doctrine\Configuration`)

| Option | Default | Use |
|---|---|---|
| `table_prefix` / `table_suffix` | `''` / `'_audit'` | Audit table name = prefix + entity table + suffix |
| `ignored_columns` | `[]` | PHP **property** names never audited, all entities |
| `entities` | `[]` | `FQCN => options` (below) |
| `storage_mapper` | `null` | Callable (or invokable class name) `fn (string $entity, array $storageServices): StorageService` |
| `batch_size` | `0` | > 0: multi-row INSERTs at commit (faster; payload then has no `id`) |
| `full_snapshot_on_remove` | `false` | `remove` entries record every audited field's last value |
| `skip_disabled_entities` | `false` | No audit table for entities disabled by configuration |
| `compression` | `false` | `JSON COMPRESSED` diffs/extra_data on MariaDB only |
| `utf8_convert` | `false` | Convert strings to UTF-8 before storing |
| `ignored_columns_resolver`, `root_resolver`, `entity_label_resolver`, `read_scope` | `null` | Callables, see docs |
| `viewer` | `true` | Used by the bundle viewer (`page_size` also defaults `paginate()`) |

Entity options: `enabled`, `ignored_columns`, `audited_columns` (allowlist), `roles` (`['view' => [...]]`),
`max_age` (ISO 8601 duration) / `max_entries` (retention for `audit:clean`), `roots`.
Runtime: `$provider->getConfiguration()->enableAuditFor(Post::class)` / `disableAuditFor()`.
Checks: `$provider->isAuditable($e)`, `isAudited($e)`, `isAuditedField($e, 'title')`.

## Entity attributes (from the core, `DH\Auditor\Attribute`)

```php
use DH\Auditor\Attribute\{Auditable, Ignore, Security, DiffLabel, AuditRoot};

#[ORM\Entity]
#[Auditable]                               // enabled: true, maxAge: 'P7Y', maxEntries: 10_000 optional
#[Security(view: ['ROLE_ADMIN'])]          // roles checked by the core role_checker when reading
#[Ignore(field: 'inheritedSecret')]        // class-level form needs field:
class Post
{
    #[ORM\Column] #[Ignore] private string $password;                          // never in diffs
    #[ORM\Column] #[DiffLabel(resolver: CategoryLabel::class)] private int $categoryId;
}
```

- Attributes are only read on classes mapped with the `#[ORM\Entity]` attribute; XML/PHP-mapped entities must be
  listed in the `entities` option.
- Attribute values are cached in the ORM metadata cache: clear it after changing them.
- Configuration and attributes merge: config `enabled: false` wins, config `roles` override `#[Security]` per scope,
  config `ignored_columns` / `roots` are added. Ignoring always beats `audited_columns`.
- `#[DiffLabel]` resolvers implement `DH\Auditor\Contract\DiffLabelResolverInterface` and are fetched from a PSR-11
  container set with `$provider->setDiffLabelResolverLocator($container)`.
- `#[AuditRoot('visit.property')]` (repeatable) indexes entries under another entity's history (see Histories).

## Audit tables (schema v2)

One table per audited entity (single table inheritance: one table for the hierarchy, `discriminator` tells the class).

| Column | Content |
|---|---|
| `id` | BIGINT autoincrement |
| `schema_version` | `2` for current rows, `1` for legacy (1.x) rows |
| `type` | `insert`, `update`, `remove`, `associate`, `dissociate` |
| `object_id` | identifier of the audited entity (string) |
| `discriminator` | entity class in inheritance hierarchies |
| `transaction_id` | ULID (CHAR 26) shared by the entries of one flush |
| `diffs` | JSON: `{"source": {...}, "changes": {"field": {"old": ..., "new": ...}}}`; associations: `source`, `target`, `is_owning_side`, `join_table` |
| `extra_data` | JSON, custom data (`extra_data_provider` or a `LifecycleEvent` listener) |
| `blame_id` | user id |
| `blame` | JSON: `username`, `user_fqdn`, `user_firewall`, `ip` (+ `via` with a blame context) |
| `created_at` | datetime |

JSON columns are native JSON on MySQL/MariaDB, JSONB on PostgreSQL, TEXT on SQLite. When an entity has roots, one
`{table_prefix}audit_root` table per storage indexes entries under their roots.

Create/update tables with **one** of:
- `(new SchemaManager($provider))->updateAuditSchema()`, or `getUpdateAuditSchemaSql()` to get the SQL;
- `audit:schema:update --dump-sql` / `--force`;
- `doctrine:schema:update` / Doctrine Migrations: the provider adds the audit tables to the Doctrine schema
  (`postGenerateSchemaTable`), so `doctrine:migrations:diff` generates them.

Adding or removing entity fields needs no audit schema change (diffs are JSON). Removing an entity from auditing does
not drop its audit table.

### Console commands (`DH\Auditor\Provider\Doctrine\Persistence\Command`)

| Command | Class | Purpose |
|---|---|---|
| `audit:schema:update` | `UpdateSchemaCommand` | Create/update audit tables; refuses while a v1 table exists |
| `audit:schema:migrate` | `MigrateSchemaCommand` | Migrate v1 tables to v2 |
| `audit:clean` | `CleanAuditLogsCommand` | Delete entries older than `keep` (default `P12M`), `--date`, `-x`/`-i`, `--dry-run`, `--no-confirm` |
| `audit:export` | `ExportAuditLogsCommand` | NDJSON/JSON/CSV export, `--id`, `--from`, `--to`, `--blame-id`, `--anonymize`, `--output` |
| `audit:snapshot` | `SnapshotCommand` | Record the current state of existing entities (`--all`, `--batch-size`, `--force`, `--dry-run`) |
| `audit:roots:rebuild` | `RebuildRootsCommand` | Rebuild the `audit_root` index (`--entity`, `--dry-run`) |

Standalone: `$application->addCommand((new UpdateSchemaCommand())->setAuditor($auditor));` for each command.
They hold a host-local lock (Symfony Lock): a concurrent run fails with "already running in another process".

## Migrating from 1.x (schema v1 → v2)

v1 tables have `transaction_hash`, `blame_user`, `blame_user_fqdn`, `blame_user_firewall`, `ip`; v2 replaces them with
`transaction_id` (ULID) and `blame` (JSON). Follow the upgrade guide; the core sequence is:

1. Back up the audit tables (no rollback: legacy columns are dropped). Rehearse on a copy.
2. Stop every writer of audited entities (maintenance mode, workers, crons).
3. Install 2.x. Do **not** run `doctrine:schema:update` or a generated Doctrine migration on audit tables yet: on v1
   tables they drop the legacy columns without converting their data.
4. `audit:schema:migrate --dump-sql --convert-all` (preview), then `audit:schema:migrate --force --convert-all`.
   `--limit=N` converts a sample and keeps legacy columns (resumable); `--drop-transaction-hash` drops
   `transaction_hash` without conversion (loses grouping, only on request).
5. `audit:schema:update --force` (v2 indexes, column types).
6. Check: `audit:schema:migrate` (no option) and `audit:schema:update --dump-sql` report nothing left.
7. `audit:roots:rebuild` if entities declare roots. Update code reading `$entry->transactionHash` (removed) and raw
   SQL using the legacy columns (`blame_user` → `blame` JSON `username`, `transaction_hash` → `transaction_id`).

The `Reader` reads migrated and non-migrated tables alike.

## Reading audits

```php
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\{DateRangeFilter, JsonFilter, NullFilter, SimpleFilter};

$reader = new Reader($provider);
$query = $reader->createQuery(Post::class, [
    'object_id' => 42,            // int|string|array
    'type' => ['insert', 'update'],
    'blame_id' => '7',            // 'user_id' is an alias
    'transaction_id' => null,
    'changed_field' => 'title',   // string|list, needs native JSON + v2 table
    'blame_origin' => null,       // 'http'|'console'|'messenger'|'system'
    'page' => 1, 'page_size' => 50, // page_size null = no limit
]);
$query->addFilter(new DateRangeFilter(Query::CREATED_AT, new \DateTimeImmutable('-30 days'), null));
$entries = $query->execute();   // list<DH\Auditor\Model\Entry>, newest first
$total = $query->count();

$page = $reader->paginate($reader->createQuery(Post::class), page: 2, pageSize: 25);
// keys: results (ArrayIterator of Entry), currentPage, numPages, numResults, hasNextPage, nextPage, ...

$byEntity = $reader->getAuditsByTransactionId($ulid);   // [FQCN => list<Entry>]
```

- Filters: `SimpleFilter` (= / IN), `DateRangeFilter`, `RangeFilter`, `NullFilter` (IS NULL only),
  `JsonFilter($column, $path, $value, $operator, $strict)` (`=`, `!=`, `<>`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`,
  `IS NULL`, `IS NOT NULL`), `ChangedFieldFilter`, `BlameOriginFilter`, `RootFilter`. Filters are ANDed.
- Column constants: `Query::ID`, `TYPE`, `OBJECT_ID`, `DISCRIMINATOR`, `TRANSACTION_ID`, `USER_ID` (= `blame_id`),
  `CREATED_AT`. Order: `addOrderBy(Query::CREATED_AT, 'ASC')`; raw paging: `limit($limit, $offset)`.
- `Query::JSON` is the filter slot name of `JsonFilter`, **not a column**: `new NullFilter(Query::JSON)` produces
  `json IS NULL` and fails. Test JSON content with `JsonFilter('extra_data', 'key', null, 'IS NULL')`, or check
  `$entry->extraData === null` in PHP.
- Without native JSON (MySQL < 5.7, MariaDB < 10.2.3, PostgreSQL < 9.4, SQLite < 3.38), `JsonFilter` falls back to
  `LIKE` with a warning (`$strict = true` throws instead); `ChangedFieldFilter` and `BlameOriginFilter` throw.
- `createQuery()` throws `AccessDeniedException` when the core `role_checker` denies the `view` role, and
  `InvalidArgumentException` for a non-audited entity. Single table inheritance: `'strict' => true` (default) keeps
  only the queried class.
- `Query::iterate()` streams raw rows (`created_at` is a string) for large exports.

### `Entry` (`DH\Auditor\Model\Entry`)

Virtual properties: `id`, `schemaVersion`, `type` (plain string), `objectId`, `discriminator`, `transactionId`,
`userId`, `blame` (array), `username`, `userFqdn`, `userFirewall`, `ip`, `via`, `extraData` (**already decoded
`?array`: never `json_decode()` it**), `createdAt` (`DateTimeImmutable` in the auditor timezone).
Methods: `getDiffs()` (the `changes` of a v2 diff, the whole diff for associations, raw diff for v1),
`getDiffSource()`, `getDiffTarget()`, `isDeepDiff($field)`, `isSnapshot()`.

### Histories

`$reader->createRootQuery(Property::class, 42)->fetch(20)` returns a `RootPage` (cursor pagination: `after:`,
`since:`) merging the root's entries and those of entities declaring it with `#[AuditRoot]` / `roots`.
`$reader->getLastChanges(Post::class, 42, ['title'])` gives the last `Change` per field;
`$reader->getFieldHistory(Post::class, 42, 'title')` returns a `Query`.

## Several databases

Register one `AuditingService` per application entity manager and one `StorageService` per audit database. With more
than one `StorageService`, `storage_mapper` is **required** (otherwise `ProviderException`):

```php
$provider = new DoctrineProvider(new Configuration([
    'storage_mapper' => fn (string $entity, array $services): StorageService =>
        $entity === User::class ? $services['audit_sensitive'] : $services['audit_general'],
]));
$provider->registerStorageService(new StorageService('audit_sensitive', $sensitiveEm));
$provider->registerStorageService(new StorageService('audit_general', $generalEm));
```

Audit writes to another connection are not atomic with the application transaction: on rollback the provider deletes
them best effort and logs failures to `$provider->setLogger($psrLogger)`.

## What is NOT audited

- DQL `UPDATE`/`DELETE` (`$em->createQuery()`), query builder bulk updates and raw DBAL/SQL: they bypass the
  UnitOfWork. Only changes flushed through `persist()` / `remove()` / `flush()` are audited.
- Entities with a composite primary key (they can still be association targets).
- Anything flushed on a connection without `AuditorMiddleware`.
- To-many association changes are `associate`/`dissociate` entries, not field diffs; `ignored_columns` and
  `audited_columns` do not filter them.

## Common mistakes

- Forgetting `AuditorMiddleware` on the connection: tables exist, flushes succeed, no audit row is written.
- Putting SQL column names in `ignored_columns` / `audited_columns` / `#[Ignore(field:)]`: they take **property** names.
- Calling `json_decode()` on `$entry->extraData`, or reading `$entry->transactionHash` (removed in 2.x).
- `new NullFilter(Query::JSON)`: there is no `json` column.
- Reading `$payload['id']` in a `LifecycleEvent` listener with `batch_size > 0`: buffered entries have no id yet.
- Running `doctrine:schema:update --force` on 1.x audit tables before `audit:schema:migrate`: data loss.
- Expecting the `extra_data_provider` to be called per entry: it is called once per flush; use a `LifecycleEvent`
  listener (`$event->entity`) for per-entity data.
- Registering several `StorageService`s without `storage_mapper`.
- Adding `#[Auditable]` to an XML-mapped entity, or changing attributes without clearing the metadata cache.

## Documentation

Published docs (https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/):

- Quick start: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/getting-started/quick-start
- Configuration: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/configuration
- Attributes: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/attributes
- Schema and commands: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/schema
- Services and workers: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/services
- Multi-database: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/multi-database
- Querying: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying
- Filters: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying/filters
- Entry: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying/entry
- Histories: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/querying/history
- Extra data: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/extra-data
- Upgrade guides (`docs/upgrade/` in the repository): https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/upgrade
- Upgrade 1.x to 2.0: https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/upgrade/v2
