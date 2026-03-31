# Upgrade Guide

> **Navigate between major versions of auditor-doctrine-provider**

This section contains upgrade guides for major versions.

## 📚 Upgrade Guides

- ⬆️ [Upgrading to 2.0](v2.md) - From 1.x to 2.0

## 📋 Version Support

| Version | Status                | Support Until |
|:--------|:----------------------|:--------------|
| 2.x     | Active development 🚀 | Current       |
| 1.x     | End of Life           | -             |

## ✅ General Upgrade Process

> [!IMPORTANT]
> Always follow these steps when upgrading:

1. 📖 **Read the upgrade guide** for your target version
2. 📦 **Update dependencies** in `composer.json`
3. 🔄 **Run Composer update**
4. 🗄️ **Run schema migration** (if upgrading from 1.x)
5. ✅ **Run tests**

```bash
# Update dependencies
composer require damienharper/auditor-doctrine-provider:^2.0

# Migrate existing audit tables (preserves all data)
bin/console audit:schema:migrate --force --convert-all

# Run tests
bin/phpunit
```
