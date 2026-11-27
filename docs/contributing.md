---
description: "Contribute to auditor-doctrine-provider: development setup, running the tests locally or in Docker on each database, QA tools, benchmarks and pull requests."
---

# Contributing

> **Thank you for your interest in contributing to auditor-doctrine-provider!**

## 🤝 Ways to Contribute

- 🐛 **Report bugs** - Submit issues on GitHub
- 💡 **Suggest features** - Open a discussion or issue
- 📖 **Improve documentation** - Fix typos, add examples, clarify explanations
- 🔧 **Submit code** - Fix bugs or implement new features
- ⭐ **Star the project** - Show your support

## 💻 Code Contributions

All code contributions are made via **Pull Requests (PR)**. Direct commits to the `main` branch are not allowed.

### 🚀 Development Setup

1. Fork the repository on GitHub
2. Clone your fork locally:

```bash
git clone https://github.com/YOUR_USERNAME/auditor-doctrine-provider.git
cd auditor-doctrine-provider
```

3. Install dependencies:

```bash
composer install
```

4. Create a branch for your changes:

```bash
git checkout -b feature/my-new-feature
```

#### Working in a Git worktree (optional)

To work on several branches in parallel, the `Makefile` creates [Git worktrees](https://git-scm.com/docs/git-worktree) in `.claude/worktrees/<name>/` (git-ignored). A new branch starts from the freshly fetched default branch. Each worktree gets its own `vendor/` and `tools/` dependencies, and the `wt-*` targets run the Composer scripts with your local PHP.

```bash
# Create a worktree for a new branch and install its dependencies
make wt-create branch=feature/my-new-feature

# QA and tests in that worktree (wt= can be omitted when make runs from inside it)
make wt-qa wt=feature-my-new-feature
make wt-test wt=feature-my-new-feature args='--filter=ReaderTest'

# The Docker targets also work from a worktree
make -C .claude/worktrees/feature-my-new-feature tests

# Remove the worktree once its branch is merged (refused while it has uncommitted changes)
make wt-clean wt=feature-my-new-feature confirm=yes
```

Run `make help` for the other targets (`wt-init`, `wt-list`, `wt-exec`, `wt-phpstan`).

### 🧪 Running Tests

#### Quick Tests (Local PHP)

```bash
# Run all tests (SQLite, no Docker needed)
composer test

# Run tests with coverage
composer test:coverage

# Run tests with testdox output
composer testdox

# Run a specific test class
vendor/bin/phpunit --filter=ReaderTest
```

#### 🐳 Testing with Docker (Recommended)

The project includes a `Makefile` that allows you to test against different combinations of PHP versions, Symfony versions, and databases using Docker containers.

> [!TIP]
> This ensures your code works across all supported environments before submitting a PR.

**Prerequisites:**
- Docker
- Docker Compose
- Make

**Available Make Targets:**

| Target    | Description                                         |
|-----------|-----------------------------------------------------|
| `tests`   | Run the test suite using PHPUnit                    |
| `cs-fix`  | Run PHP-CS-Fixer to fix coding standards            |
| `phpstan` | Run PHPStan for static code analysis                |
| `rector`  | Run Rector to apply automated refactorings          |
| `qa`      | Run Rector, PHP-CS-Fixer and PHPStan, in this order |
| `bench`   | Run PHPBench benchmarks (statistical, reproducible) |
| `profile` | Profile with Blackfire (requires `BLACKFIRE_*` vars) |
| `help`    | Display available commands and options              |

**Options:**

| Option | Values                                | Default  | Description              |
|--------|---------------------------------------|----------|--------------------------|
| `php`  | `8.4`, `8.5`, `8.6`                   | `8.4`    | PHP version              |
| `sf`   | `8.1`, `8.2`                          | `8.1`    | Symfony version          |
| `db`   | `sqlite`, `mysql`, `pgsql`, `mariadb` | `sqlite` | Database type            |
| `args` | Any PHPUnit/tool arguments            | (varies) | Additional arguments     |

**Valid PHP/Symfony Combinations:**

| PHP Version | Symfony Versions |
|-------------|------------------|
| 8.4         | 8.1, 8.2         |
| 8.5         | 8.1, 8.2         |
| 8.6         | 8.1, 8.2         |

**Examples:**

```bash
# Show all available commands and options
make help

# Run tests with defaults (PHP 8.4, Symfony 8.1, SQLite)
make tests

# Run tests with MySQL
make tests db=mysql

# Run tests with PostgreSQL
make tests db=pgsql

# Run tests with MariaDB
make tests db=mariadb

# Run tests with a specific PHP version
make tests php=8.5

# Full specification
make tests php=8.4 sf=8.1 db=mysql

# Run specific test class
make tests args='--filter=ReaderTest'

# Run tests with coverage
make tests args='--coverage-html=coverage'
```

**Testing the Full Matrix:**

> [!IMPORTANT]
> Before submitting a pull request, test against multiple database types to catch driver-specific issues.

```bash
# Test all databases with PHP 8.4
make tests php=8.4 db=sqlite
make tests php=8.4 db=mysql
make tests php=8.4 db=pgsql
make tests php=8.4 db=mariadb
```

**How It Works:**

The Makefile uses Docker Compose to spin up containers with the specified PHP version and database. The configuration files are located in `tools/docker/`:

```
tools/docker/
├── compose.yaml             # Base configuration
├── compose.mysql.yaml       # MySQL 8 service
├── compose.pgsql.yaml       # PostgreSQL 15 service
├── compose.mariadb.yaml     # MariaDB 11 service
├── compose.blackfire.yaml   # Blackfire agent sidecar (profiling)
└── Dockerfile               # PHP CLI image (pdo_mysql + pdo_pgsql + xdebug, + blackfire stage)
```

#### Migration end-to-end test

`tests/MigrationE2E` checks `audit:schema:migrate` on data written by provider 1.x: provider 1.x, installed in
`tools/migration-e2e/v1`, writes audit entries, then the test migrates them and compares every row. It is excluded
from the default suite:

```bash
make migration-e2e db=mysql            # in Docker: sqlite (default), mysql, pgsql, mariadb

# or locally
composer install --working-dir=tools/migration-e2e/v1
E2E_DATABASE_URL=… vendor/bin/phpunit --group migration-e2e
```

> [!CAUTION]
> The generator drops every table of the `E2E_DATABASE_URL` database: point it to a throwaway database.

### ⚡ Benchmarking

The project ships with **PHPBench** benchmark suites and a **Blackfire** profiling script to measure the performance impact of changes on the Doctrine flush pipeline (audit entry generation + persistence), the read model and `audit:snapshot`.

Benchmark files live in `benchmarks/`:

```
benchmarks/
├── AuditBench.php       # flush pipeline — 6 scenarios
├── ReadBench.php        # Reader / Query — history, first and deep pages, count, transaction
├── SnapshotBench.php    # audit:snapshot (Snapshotter)
├── BenchSetupTrait.php  # shared provider, entity manager, schema and audit rows seeding
└── profile.php          # Standalone Blackfire profiling script
```

#### Composer Scripts (Recommended)

The quickest way to run benchmarks locally. All scripts honour the `BENCH_N` environment variable (default: `50`) to control the number of entities per flush.

| Script | Description |
|--------|-------------|
| `composer bench` | Run all benchmarks (aggregate report) |
| `composer bench:baseline` | Store a baseline tagged `before` |
| `composer bench:compare` | Compare current code against the `before` baseline |
| `composer bench:html` | Generate `benchmark-report.html` vs baseline |
| `composer profile` | Run the Blackfire profiling script (dry run without the CLI) |

```bash
# Simple run
BENCH_N=1000 composer bench

# Take a baseline (on main)
BENCH_N=1000 XDEBUG_MODE=off composer bench:baseline

# Compare (on your branch)
BENCH_N=1000 XDEBUG_MODE=off composer bench:compare

# Generate HTML artifact
BENCH_N=1000 XDEBUG_MODE=off composer bench:html
# → benchmark-report.html
```

> [!TIP]
> Pass `XDEBUG_MODE=off` to disable the xdebug overhead and get production-representative timings.

Two more environment variables shape the measured setup:

- `BENCH_DATABASE_URL` runs the benchmarks on a database server instead of an in-memory SQLite database, e.g. `pdo-mysql://auditor:password@127.0.0.1:3361/auditor` or `pdo-pgsql://postgres:password@127.0.0.1:5433/auditor`. Every table of that database is dropped first: use a disposable one.
- `BENCH_BATCH_SIZE` sets the `batch_size` provider option (default: `0`, one `INSERT` per audit entry).
- `BENCH_SEED_ROWS` fills the audit tables of `AuditBench` with that many rows before the measured flush (default: `0`): index maintenance only shows on large tables.
- `BENCH_READ_ROWS` (default: `50000`) and `BENCH_EXTRA_TABLES` (default: `200`) size the audit table and the database of `ReadBench`. `SnapshotBench` snapshots `BENCH_N` posts (default: `2000`).

To compare with a branch that does not have a benchmark yet, copy the `benchmarks/` directory into a checkout of that branch and take the baseline there.

```bash
# Measure the batched audit entries on PostgreSQL, back to back with the default
BENCH_N=500 BENCH_DATABASE_URL=pdo-pgsql://postgres:password@127.0.0.1:5433/auditor XDEBUG_MODE=off composer bench
BENCH_N=500 BENCH_DATABASE_URL=pdo-pgsql://postgres:password@127.0.0.1:5433/auditor BENCH_BATCH_SIZE=500 XDEBUG_MODE=off composer bench
```

Extra PHPBench arguments can be appended after `--`:

```bash
# Custom tag
BENCH_N=1000 composer bench -- --tag=my_tag --store
BENCH_N=1000 composer bench -- --ref=my_tag --progress=plain

# Run a single scenario
BENCH_N=1000 composer bench -- --filter=benchInsert
```

#### Running Benchmarks via Docker

Use `make bench` to run against a specific PHP version inside the same containerised environment used for the test suite. Results are comparable across machines and CI runs.

```bash
# Run with defaults (N=50, PHP 8.4)
make bench

# Run with 1 000 entities, xdebug disabled
BENCH_N=1000 XDEBUG_MODE=off make bench

# Run on PHP 8.5
BENCH_N=1000 make bench php=8.5

# Pass extra PHPBench arguments
make bench args='--filter=benchInsert'
```

#### Before/After Comparison

This workflow quantifies the **performance impact** of a branch against `main`.

PHPBench stores runs as XML files in `.phpbench/` (gitignored). `--tag` names a stored run; `--ref` replays it alongside the current run. Both branches must use the **same `BENCH_N`** and the **same environment** (same PHP version, same xdebug mode) for a valid comparison.

**Via Composer (local PHP, recommended):**

```bash
# 1. On main — store the baseline
git checkout main
BENCH_N=1000 XDEBUG_MODE=off composer bench:baseline

# 2. On your branch — compare
git checkout my-branch
BENCH_N=1000 XDEBUG_MODE=off composer bench:compare
```

**Via Docker (reproducible environment):**

```bash
git checkout main
BENCH_N=1000 XDEBUG_MODE=off make bench php=8.4 args='--tag=before --store'

git checkout my-branch
BENCH_N=1000 XDEBUG_MODE=off make bench php=8.4 args='--ref=before --progress=plain'
```

With `--ref=before`, each subject line in the progress output shows the inline comparison:

```
benchInsert    I5 [Mo850μs (actual) vs. Mo1.2ms (before)] -29.17% (±1.8%)
benchUpdate    I5 [Mo9.2ms (actual) vs. Mo12.1ms (before)] -23.97% (±2.1%)
...
```

A **negative percentage** means the current branch is faster. The result is statistically meaningful when `|gain| > rstdev`.

#### Capturing Results for a PR Description

```bash
BENCH_N=1000 XDEBUG_MODE=off composer bench:compare 2>&1 \
    | grep -E "bench[A-Z]" > benchmark-summary.txt
```

Paste the content of `benchmark-summary.txt` into the PR body inside a fenced code block.

#### Blackfire Profiling (Flame Graphs)

For deep-dive profiling (call graph, function-level hotspots), use the Blackfire script.

**Prerequisites:** A [Blackfire.io](https://blackfire.io) account (free tier works). Set the following environment variables:

```bash
export BLACKFIRE_SERVER_ID=<your-server-id>
export BLACKFIRE_SERVER_TOKEN=<your-server-token>
export BLACKFIRE_CLIENT_ID=<your-client-id>
export BLACKFIRE_CLIENT_TOKEN=<your-client-token>
```

**Dry run (no Blackfire account needed):**

```bash
BENCH_N=100 composer profile
```

**Full Blackfire profile:**

```bash
# With the Blackfire CLI installed locally
BENCH_N=100 blackfire run php benchmarks/profile.php

# Via Docker (Blackfire agent sidecar)
BENCH_N=100 make profile
```

The `make profile` target spins up a Blackfire agent container alongside php-cli using `compose.blackfire.yaml`. The resulting profile URL is printed in the terminal and opens in your Blackfire dashboard.

#### Benchmark Scenarios

| Subject | What is measured |
|---------|-----------------|
| `benchInsert` | N Author inserts → N audit `INSERT` entries |
| `benchUpdate` | N Post updates (title + body + created_at) → N audit `UPDATE` entries |
| `benchRemove` | N Author removals → N audit `REMOVE` entries |
| `benchAssociate` | N Post→Tag ManyToMany links → N audit `ASSOCIATE` entries |
| `benchDissociate` | N Post→Tag ManyToMany unlinks → N audit `DISSOCIATE` entries |
| `benchMixed` | Realistic flush: N/2 inserts + N/4 updates (2 fields) + N/4 removes |

All scenarios use an **in-memory SQLite database** so results are environment-independent. The `BENCH_N` env var (default `50`) controls the number of entities per flush.

### 🧹 Code Quality

Before submitting, ensure your code passes all quality checks.

#### Using Composer (Local)

```bash
# Run all QA tools (rector + cs-fix + phpstan)
composer qa

# Individual tools
composer cs-check    # Check code style (dry run)
composer cs-fix      # Fix code style
composer phpstan     # Static analysis
composer rector      # Automated refactoring suggestions
```

> [!NOTE]
> PHPStan, php-cs-fixer, and rector each have their own isolated Composer project under `tools/`. The `composer` scripts call them via `tools/` paths automatically — do not call them directly via `vendor/bin/`.

#### Using Make (Docker)

```bash
# Run Rector, PHP-CS-Fixer and PHPStan, in this order
make qa

# Run Rector alone
make rector

# Run PHP-CS-Fixer
make cs-fix

# Run PHPStan
make phpstan

# With specific PHP version
make phpstan php=8.5

# With custom arguments
make phpstan args='analyse src --level=9'
make cs-fix args='fix --dry-run'
```

### 📝 Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add support for inheritance discriminator in audit table
fix: ensure ManyToMany dissociations are captured correctly
test: add MySQL integration tests for SchemaManager
docs: document multi-database setup
chore: bump phpunit to ^12.0
```

### 🔄 Pull Request Process

1. Ensure all tests pass (ideally on multiple databases)
2. Run code quality tools (`composer qa`)
3. Update documentation if needed
4. Submit the pull request against `main`
5. Respond to review feedback

### 🤖 Continuous Integration (CI)

When you submit a Pull Request, GitHub Actions will automatically run:

- **PHPUnit tests** across the full matrix:
  - PHP versions: 8.4, 8.5, 8.6
  - Symfony versions: 8.1, 8.2
  - Databases: SQLite, MySQL, PostgreSQL
- **PHP-CS-Fixer** for code style validation
- **PHPStan** for static analysis
- **Code coverage** report

> [!WARNING]
> Your PR must pass all CI checks before it can be merged. If a check fails, review the logs to identify and fix the issue.

> [!TIP]
> Run `make tests db=mysql` and `make tests db=pgsql` locally before pushing to catch database-specific issues early.

### ✏️ Writing Tests

Tests live in `tests/` and mirror the `src/` structure. The suite uses **PHPUnit** and relies on `SchemaSetupTrait` to bootstrap a Doctrine EntityManager and audit schema.

The database connection is driven by the `DATABASE_URL` environment variable. Tests fall back to SQLite `:memory:` when `DATABASE_URL` is not set.

**Test class skeleton:**

```php
<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine;

use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\SchemaSetupTrait;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MyFeatureTest extends TestCase
{
    use SchemaSetupTrait;

    protected function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            MyEntity::class => ['enabled' => true],
        ]);
    }

    public function testFeatureWorksAsExpected(): void
    {
        // Arrange
        // Act
        // Assert
    }
}
```

> [!IMPORTANT]
> Override `configureEntities()` — **not** `setUp()` — to register entities for a test class. Overriding `setUp()` bypasses the trait's schema bootstrap and leaves audit tables uncreated.

**Running your tests:**

```bash
# Run only your new tests
make tests args='--filter=MyFeatureTest'

# Run with coverage to ensure good test coverage
composer test:coverage
```

## 🐛 Reporting Bugs

When reporting bugs, please include:

1. **Package version** — `composer show damienharper/auditor-doctrine-provider`
2. **PHP version** — `php -v`
3. **Symfony version** — `composer show symfony/framework-bundle`
4. **Database** — MySQL, PostgreSQL, SQLite, MariaDB, etc.
5. **Steps to reproduce** — Minimal code example
6. **Expected behavior** — What should happen
7. **Actual behavior** — What actually happens
8. **Error messages** — Full stack trace if available

## 💡 Feature Requests

For feature requests:

1. Check existing issues to avoid duplicates
2. Describe the use case
3. Explain why existing features don't meet your needs
4. Suggest a possible implementation if you have ideas

## 📖 Documentation Contributions

Documentation lives in the `docs/` directory and uses Markdown.

## 💬 Code of Conduct

- Be respectful and inclusive
- Welcome newcomers
- Provide constructive feedback
- Focus on what is best for the community

## ❓ Questions?

- Open a [GitHub Discussion](https://github.com/DamienHarper/auditor-doctrine-provider/discussions)
- Check existing issues and discussions first

## 📜 License

By contributing, you agree that your contributions will be licensed under the [MIT License](https://opensource.org/licenses/MIT).

---

## Related Projects

- **[auditor](https://github.com/DamienHarper/auditor)** — Core library
- **[auditor-bundle](https://github.com/DamienHarper/auditor-bundle)** — Symfony bundle
