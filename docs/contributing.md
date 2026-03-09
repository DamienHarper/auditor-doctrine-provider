# Contributing

> **How to contribute to auditor-doctrine-provider**

Thank you for your interest in contributing! This guide explains how to set up the development environment and submit contributions.

## 🛠️ Development Setup

### Prerequisites

- Docker and Docker Compose (recommended for CI parity)
- Make
- PHP >= 8.4 (for local development without Docker)

### Clone and Install

```bash
git clone https://github.com/DamienHarper/auditor-doctrine-provider.git
cd auditor-doctrine-provider
composer install
```

### Run the Tests

```bash
# Run tests locally with SQLite (no Docker needed)
composer test

# Run tests with Docker (CI parity)
make tests

# Run tests with MySQL
make tests db=mysql

# Run tests with PostgreSQL
make tests db=pgsql

# Run tests with MariaDB
make tests db=mariadb

# Run tests with a specific PHP version
make tests php=8.5

# Run a specific test class
vendor/bin/phpunit --filter=ReaderTest

# Run a specific test method
vendor/bin/phpunit --filter=testMethodName
```

### Run QA Tools

```bash
# Run all QA tools (rector + cs-fix + phpstan)
composer qa

# Run individually
composer rector
composer cs-fix
composer phpstan

# Dry-run rector (preview changes without applying)
composer rector-check
```

> [!NOTE]
> PHPStan, php-cs-fixer, and rector each have their own isolated Composer project under `tools/`. The `composer` scripts call them via `tools/` paths automatically — do not call them directly via `vendor/bin/`.

## 📐 Coding Standards

This project follows [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html) and is enforced by php-cs-fixer. Run `composer cs-fix` before submitting a pull request.

PHPStan runs at level max; all new code must be fully type-hinted.

## 🏷️ Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add support for inheritance discriminator in audit table
fix: ensure ManyToMany dissociations are captured correctly
test: add MySQL integration tests for SchemaManager
docs: document multi-database setup
chore: bump phpunit to ^12.0
```

## 🔄 Pull Request Process

1. **Fork** the repository
2. **Create a branch** from `main`: `git checkout -b feat/my-feature`
3. **Write tests** for any new functionality
4. **Run QA** to ensure your changes pass all checks:
   ```bash
   composer qa
   composer test
   ```
5. **Submit a pull request** against `main`

## 🧪 Writing Tests

Tests live in `tests/` and mirror the `src/` structure. The test suite uses **PHPUnit** and relies on `SchemaSetupTrait` to bootstrap a Doctrine EntityManager and audit schema.

The database connection is driven by the `DATABASE_URL` environment variable. Tests fall back to SQLite `:memory:` when `DATABASE_URL` is not set.

```bash
# Run with coverage
composer test:coverage

# Run in testdox format
composer testdox
```

## 📋 Supported PHP Versions

| PHP Version | Status    |
|-------------|-----------|
| 8.4         | ✅ Tested |
| 8.5         | ✅ Tested |

## 📝 License

By contributing, you agree that your contributions will be licensed under the [MIT License](https://opensource.org/licenses/MIT).

---

## Related Projects

- **[auditor](https://github.com/DamienHarper/auditor)** — Core library
- **[auditor-bundle](https://github.com/DamienHarper/auditor-bundle)** — Symfony bundle
