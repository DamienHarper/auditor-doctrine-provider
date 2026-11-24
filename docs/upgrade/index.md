---
description: Upgrade guides between major versions of auditor-doctrine-provider, the support status of the 1.x and 2.x lines, and the general upgrade procedure.
---

# Upgrade Guide

> **Navigate between major versions of auditor-doctrine-provider**

This section contains upgrade guides for major versions.

## 📚 Upgrade Guides

- ⬆️ [Upgrading to 2.0](v2.md) - From 1.x to 2.0

## 📋 Version Support

| Version | Status                | Support Until |
|:--------|:----------------------|:--------------|
| 2.x     | Active development 🚀 | Current       |
| 1.x     | Bug and security fixes | Until next major |

## ✅ General Upgrade Process

> [!IMPORTANT]
> Always follow these steps when upgrading:

1. 📖 **Read the upgrade guide** for your target version
2. 📦 **Update dependencies** in `composer.json`
3. 🔄 **Run Composer update**
4. 🗄️ **Run schema migration** (if upgrading from 1.x)
5. ✅ **Run tests**

> [!CAUTION]
> Upgrading from 1.x migrates every audit table and drops the legacy columns: back up the audit tables
> and follow the [step-by-step procedure](v2.md#-upgrade-steps), in its order.

```bash
# Update dependencies
composer require damienharper/auditor-doctrine-provider:^2.0

# Migrate existing audit tables (after a backup, with audit writes stopped)
bin/console audit:schema:migrate --force --convert-all

# Add the indexes of the v2 schema
bin/console audit:schema:update --force

# Run tests
bin/phpunit
```
