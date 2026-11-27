---
description: "Install auditor-doctrine-provider with Composer: PHP, Doctrine DBAL/ORM and auditor requirements of the 2.x and 1.x lines, and when to use auditor-bundle."
---

# Installation

> **Install auditor-doctrine-provider using Composer**

## 📋 Requirements

### Version 2.x (Current)

| Requirement               | Version  |
|---------------------------|----------|
| PHP                       | >= 8.4   |
| doctrine/dbal             | >= 4.6   |
| doctrine/orm              | >= 3.7   |
| symfony/cache             | >= 8.1   |
| symfony/lock              | >= 8.1   |
| damienharper/auditor      | >= 5.0   |

Tested with PHP 8.4, 8.5 and 8.6, and Symfony 8.1 and 8.2.

### Previous Versions

| Version | PHP    | damienharper/auditor |
|---------|--------|----------------------|
| 1.x     | >= 8.4 | >= 4.1               |

## 📦 Install via Composer

```bash
composer require damienharper/auditor-doctrine-provider
```

This will install the latest stable version along with all required dependencies including the `damienharper/auditor` core library.

### Symfony users

If you use the Symfony framework, install **[auditor-bundle](https://github.com/DamienHarper/auditor-bundle)** instead — it wires everything automatically:

```bash
composer require damienharper/auditor-bundle
```

## 📚 Dependencies

| Package                             | Purpose                               |
|-------------------------------------|---------------------------------------|
| `damienharper/auditor`              | Core audit-log engine                 |
| `doctrine/dbal`                     | Database abstraction layer            |
| `doctrine/orm`                      | Object-relational mapper              |
| `symfony/cache`                     | Attribute metadata cache              |
| `symfony/lock`                      | Schema update locking                 |
| `psr/log`                           | Logging of failed clean-ups           |

Some packages the provider uses are not required directly:

| Package                             | Purpose                               | Installed through      |
|-------------------------------------|---------------------------------------|------------------------|
| `symfony/options-resolver`          | Configuration option handling         | `damienharper/auditor` |
| `symfony/console`                   | The `audit:*` console commands        | `doctrine/orm`         |

> [!NOTE]
> The `audit:*` commands are Symfony Console commands. Without the bundle, register them in your own
> `Symfony\Component\Console\Application` (see [Registering Commands](../providers/doctrine/schema.md#registering-commands-standalone))
> and require `symfony/console` explicitly if you rely on it directly.

---

## Next Steps

- 🚀 [Quick Start Guide](quick-start.md)
- ⚙️ [Configuration Reference](../providers/doctrine/configuration.md)
- 🗄️ [DoctrineProvider](../providers/doctrine/index.md)
