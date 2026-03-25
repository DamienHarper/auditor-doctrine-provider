# Installation

> **Install auditor-doctrine-provider using Composer**

## 📋 Requirements

### Version 2.x (Current)

| Requirement               | Version  |
|---------------------------|----------|
| PHP                       | >= 8.4   |
| doctrine/dbal             | >= 4.0   |
| doctrine/orm              | >= 3.2   |
| symfony/cache             | >= 8.0   |
| symfony/lock              | >= 8.0   |
| symfony/event-dispatcher  | >= 8.0   |
| damienharper/auditor      | >= 4.0   |

### Previous Versions

| Version | PHP    | damienharper/auditor |
|---------|--------|----------------------|
| 1.x     | >= 8.4 | >= 4.0               |

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
| `symfony/event-dispatcher`          | LifecycleEvent dispatching            |
| `symfony/options-resolver`          | Configuration option handling         |

---

## Next Steps

- 🚀 [Quick Start Guide](quick-start.md)
- ⚙️ [Configuration Reference](../providers/doctrine/configuration.md)
- 🗄️ [DoctrineProvider](../providers/doctrine/index.md)
