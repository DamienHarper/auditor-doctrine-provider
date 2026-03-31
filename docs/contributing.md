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
| `help`    | Display available commands and options              |

**Options:**

| Option | Values                                | Default  | Description              |
|--------|---------------------------------------|----------|--------------------------|
| `php`  | `8.4`, `8.5`                          | `8.4`    | PHP version              |
| `sf`   | `8.0`                                 | `8.0`    | Symfony version          |
| `db`   | `sqlite`, `mysql`, `pgsql`, `mariadb` | `sqlite` | Database type            |
| `args` | Any PHPUnit/tool arguments            | (varies) | Additional arguments     |

**Valid PHP/Symfony Combinations:**

| PHP Version | Symfony Versions |
|-------------|------------------|
| 8.4         | 8.0              |
| 8.5         | 8.0              |

**Examples:**

```bash
# Show all available commands and options
make help

# Run tests with defaults (PHP 8.4, Symfony 8.0, SQLite)
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
make tests php=8.4 sf=8.0 db=mysql

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
└── Dockerfile               # PHP CLI image (pdo_mysql + pdo_pgsql + xdebug)
```

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
  - PHP versions: 8.4, 8.5
  - Symfony versions: 8.0
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
