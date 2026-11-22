# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`auditor-doctrine-provider` (namespace `DH\Auditor\Provider\Doctrine`) is the Doctrine ORM provider of the [`damienharper/auditor`](https://github.com/DamienHarper/auditor) audit-log library: it detects entity changes on flush, writes them to audit tables in the same database transaction, and reads them back. Active development targets **2.x** on `main` (PHP ≥ 8.4, Doctrine ORM ^3.7, DBAL ^4.6, Symfony ^8.0 components, PHPUnit 13).

## auditor family

| Package | Repository | Default branch | Role |
|---|---|---|---|
| `damienharper/auditor` | `DamienHarper/auditor` | `master` | Core: contracts, models, events |
| `damienharper/auditor-doctrine-provider` | `DamienHarper/auditor-doctrine-provider` | `main` | Doctrine ORM provider: change detection, storage, reader, schema |
| `damienharper/auditor-bundle` | `DamienHarper/auditor-bundle` | `master` | Symfony bundle: configuration, viewer, export |

- Dependency order: core → provider → bundle. Downstream packages require the upstream ones at `@dev`, so their CI runs against the upstream default branch.
- A change spanning several packages lands as one PR per repository, in that order; a downstream PR goes green once the upstream one is merged.
- Each repository has its own `CLAUDE.md`, `Makefile` (`wt-*` worktree targets, Docker matrix) and `composer agent-*` scripts.

## Commands

### Run tests locally
```bash
composer test                                  # all tests, in-memory SQLite, no coverage
composer test:coverage                         # with Xdebug coverage
vendor/bin/phpunit --filter=ReaderTest         # single test class
DATABASE_URL="mysql://…" composer test         # another database (see "Databases" below)
```

### Code quality
```bash
composer cs-fix      # fix style (php-cs-fixer)
composer cs-check    # dry-run style check
composer phpstan     # static analysis (level max)
composer rector      # automated refactorings (rector-check: dry run)
composer qa          # rector + cs-fix + phpstan
```

### Agent commands (token-efficient, silent output)
When running as an AI agent, use these variants instead — they suppress progress bars and
verbose output while still surfacing errors:
```bash
composer agent-cs-fix      # php-cs-fixer --quiet
composer agent-phpstan     # phpstan --no-progress
composer agent-rector      # rector --no-progress-bar
composer agent-test        # phpunit --no-progress
composer agent-qa          # agent-rector + agent-cs-fix + agent-phpstan
```

### Docker matrix (Makefile)
```bash
make tests php=8.5 db=pgsql             # PHPUnit in Docker; db: sqlite (default), mysql, pgsql, mariadb
make tests args='--filter=ReaderTest'
make qa                                 # rector + cs-fix + phpstan in Docker
make help                               # all targets and options
```
CI runs PHP 8.4/8.5/8.6 (Symfony 8.2, plus 8.0 on PHP 8.4/SQLite) on SQLite, MySQL and PostgreSQL; MariaDB is only covered by `make tests db=mariadb`.

### Git worktrees (Makefile)
Parallel work happens in worktrees under `.claude/worktrees/<name>/` (git-ignored), each with its
own `vendor/` and `tools/`. The `wt-*` targets run the `composer agent-*` scripts with the host
PHP; `wt=<name>` can be omitted when `make` runs from inside the worktree.
```bash
make wt-create branch=feature/my-feature   # new branch from the freshly fetched origin/HEAD, then install
make wt-qa wt=feature-my-feature           # composer agent-qa
make wt-test wt=feature-my-feature args='--filter=ReaderTest'
make wt-clean wt=feature-my-feature confirm=yes   # refused while the worktree has uncommitted changes
```
Other targets: `wt-init`, `wt-list`, `wt-exec args=<cmd>`, `wt-phpstan`. The Docker targets also
work from a worktree (`make -C .claude/worktrees/<name> tests db=pgsql`).

### Benchmarks and profiling
```bash
composer bench:baseline     # on the base branch: store a "before" run
composer bench:compare      # on the branch: compare with "before" (negative % = faster)
make bench                  # PHPBench in Docker (BENCH_N entities per flush, default 50)
make profile                # Blackfire, needs the BLACKFIRE_* credentials
```
Compare back to back, with the same `BENCH_N` and `XDEBUG_MODE=off`. See `docs/contributing.md`.

## Architecture

### How auditing works

`DoctrineProvider` (`src/Provider/Doctrine/DoctrineProvider.php`) extends the core `AbstractProvider` and registers, on each auditing entity manager, `DoctrineSubscriber` (`onFlush`, `postFlush`, and Gedmo's `postSoftDelete` when Gedmo is installed) plus two schema listeners.

1. `onFlush`: `TransactionManager::populate()` → `TransactionHydrator` turns the UnitOfWork changesets into insert / update / remove / associate / dissociate entries on the entity manager's `Transaction` model.
2. On the first flush of a database transaction, the subscriber registers a flusher with `AuditorDriver::addFlusher()`.
3. `AuditorConnection::commit()` runs the flushers before the real COMMIT: `TransactionManager::process()` → `TransactionProcessor` builds the audit rows (`schema_version` 2, blame computed once per flush) and dispatches a `LifecycleEvent` per entry, persisted by `DoctrineProvider::persist()`. Audits are therefore written in the same database transaction.
4. If a flusher throws, the connection rolls back; `rollBack()` clears the flushers.

The DBAL `AuditorMiddleware` must be configured on the connection, otherwise nothing is written (the test `ConnectionTrait` does it).

### Key classes

| Class | Path (under `src/Provider/Doctrine/`) | Purpose |
|---|---|---|
| `DoctrineProvider` | `DoctrineProvider.php` | Registers listeners, persists audit rows, auditable checks |
| `Configuration` | `Configuration.php` | Entities, table prefix/suffix, ignored columns, storage mapper, viewer |
| `DoctrineSubscriber` | `Auditing/Event/DoctrineSubscriber.php` | `onFlush` / `postFlush` entry point |
| `TransactionHydrator` / `TransactionProcessor` | `Auditing/Transaction/` | Changesets → entries → audit rows |
| `AuditorMiddleware` / `AuditorDriver` / `AuditorConnection` | `Auditing/DBAL/Middleware/` | Write the audits at commit |
| `AttributeLoader` | `Auditing/Attribute/AttributeLoader.php` | Reads `#[Auditable]`, `#[Ignore]`, `#[Security]` |
| `SchemaManager` | `Persistence/Schema/SchemaManager.php` | Creates and updates the audit tables |
| `Reader` / `Query` / `Filter\*` | `Persistence/Reader/` | Read model: queries, filters, pagination |
| `RetentionPruner` | `Persistence/Retention/RetentionPruner.php` | Audit retention |
| Commands | `Persistence/Command/` | `audit:schema:update`, `audit:schema:migrate`, `audit:clean`, export |

### Audit tables (schema v2)

- `transaction_id` (ULID), `blame` (JSON: `username`, `user_fqdn`, `user_firewall`, `ip`), `diffs` and `extra_data` (JSON), `schema_version` (`1` legacy, `2` current). `audit:schema:migrate` upgrades v1 tables (`--convert-diffs` rewrites legacy diffs); `audit:schema:update` refuses v1 tables.
- JSON columns: native JSON on MySQL/MariaDB, JSONB on PostgreSQL (inserts need the `::jsonb` cast), TEXT where the platform has no JSON support. JSON search and `ChangedFieldFilter` need native JSON and a v2 table.
- Charset/collation column options are applied on MySQL/MariaDB only: on PostgreSQL they cause false schema diffs.

## Testing conventions

### Test structure
- `tests/Provider/Doctrine/` mirrors `src/Provider/Doctrine/`; fixtures in `tests/Provider/Doctrine/Fixtures/` (entities under `Entity/Standard`, `Entity/Standard/Blog`, `Entity/Inheritance`…).
- Issue reproductions: `tests/Provider/Doctrine/Issues/Issue<N>Test.php`, entities in `tests/Provider/Doctrine/Fixtures/Issue<N>/` (see `Issue37Test`).
- Every test class: `/** @internal */`, `#[Small]`, `final class …Test extends TestCase`, assertions through `$this->assert…()`.
- `createStub()` by default; `createMock()` only in a test that sets an `expects()`.

### Schema traits (`tests/Provider/Doctrine/Traits/Schema/`)
- `SchemaSetupTrait::setUp()` runs `parent::setUp()`, then the private hooks `createAndInitDoctrineProvider()` → `configureEntities()` → `setupEntitySchemas()` → `setupAuditSchemas()` → `setupEntities()`; `tearDown()` drops the entity and audit tables.
- `DefaultSchemaSetupTrait`: provider only, no audited entity. `BlogSchemaSetupTrait`: Author/Post/Comment/Tag audited and seeded with five transactions.
- A test class changes a hook by declaring a **private** method of the same name (`configureEntities()`, `setupEntities()`, `createAndInitDoctrineProvider()`).
- **Never override `setUp()`** in a class that uses a schema trait: the class method replaces the trait's, and `parent::setUp()` then calls `TestCase::setUp()` — no provider, no schema. Audit tables only exist for the entities set in `configureEntities()`.

### Databases
- Without `DATABASE_URL`, tests run on in-memory SQLite; with it (`mysql://`, `pgsql://`, `sqlite://`), the database is dropped and recreated per connection.
- Some tests only run on one platform (e.g. `AuditTableJsonColumnsTest` on PostgreSQL): platform bugs show up through `make tests db=…` or CI, not a local `composer test`.
- `phpunit.xml` fails on deprecations triggered by `src/`.

### `LifecycleEvent` payload and `Entry`
Payload keys: `entity`, `table`, `schema_version`, `type` (string operation, no `action` key), `object_id`, `discriminator`, `transaction_id`, `diffs` (JSON string), `extra_data`, `blame_id`, `blame` (JSON string), `created_at`. On an `Entry`, `$entry->type` is a plain string, `$entry->extraData` is already decoded, `$entry->blame` is the decoded array with the `username`, `userFqdn`, `userFirewall` and `ip` shortcuts. See the core `CLAUDE.md` for details.

## Tooling details

- **php-cs-fixer**, **phpstan**, and **rector** each have their own isolated Composer project under `tools/`. Never run them directly via `vendor/bin/` — use the `composer` scripts or the `tools/` paths (`php tools/phpstan/vendor/bin/phpstan …`).
- PHPStan runs at level max on `src/` with 100% type coverage; current suppressions are tracked in `phpstan.neon`.
- `rector.php` covers `src/` and `tests/` (PHP 8.4 set, PHPUnit/Doctrine/Symfony code quality sets).
- `composer.lock` and `symfony.lock` are git-ignored; the Docker targets delete `composer.lock` before installing.
