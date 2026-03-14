# Services

> **AuditingService and StorageService — the building blocks of the DoctrineProvider**

The DoctrineProvider relies on two types of services: **AuditingService** (source of changes) and **StorageService** (destination of audit entries).

## 🔍 Overview

| Service           | Interface                  | Responsibility                                              |
|-------------------|----------------------------|-------------------------------------------------------------|
| `AuditingService` | `AuditingServiceInterface` | Wraps an EntityManager and captures entity changes          |
| `StorageService`  | `StorageServiceInterface`  | Wraps an EntityManager used to persist audit entries        |

Both services extend `DoctrineService` and wrap a Doctrine `EntityManagerInterface`.

## 🔌 AuditingService

The `AuditingService` wraps the EntityManager whose entities should be audited.

When `DoctrineProvider::registerAuditingService()` is called, it registers the `DoctrineSubscriber` on Doctrine's event system and wires the `AuditorMiddleware` into the DBAL layer.

### Namespace

```php
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
```

### Constructor

```php
$auditingService = new AuditingService(
    'default',       // Service name
    $entityManager   // Doctrine\ORM\EntityManagerInterface
);
```

### Registration

```php
$provider->registerAuditingService($auditingService);
// ↑ This wires DoctrineSubscriber onto onFlush and AuditorMiddleware onto the DBAL connection.
//   No additional setup is needed.
```

> [!IMPORTANT]
> The EntityManager must be configured **before** the provider is registered with the auditor. The `AuditorMiddleware` must be present in the DBAL middleware stack for the transactional flush callback to work.

## 💾 StorageService

The `StorageService` wraps the EntityManager used to write audit rows. This can be the same EntityManager as the auditing service (single database) or a different one (multi-database).

### Namespace

```php
use DH\Auditor\Provider\Doctrine\Service\StorageService;
```

### Constructor

```php
$storageService = new StorageService(
    'default',       // Service name
    $entityManager   // Doctrine\ORM\EntityManagerInterface
);
```

### Registration

```php
$provider->registerStorageService($storageService);
```

## 🔗 Single Database Setup

The most common setup uses the same EntityManager for both services:

```php
<?php

use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;

$provider->registerAuditingService(new AuditingService('default', $entityManager));
$provider->registerStorageService(new StorageService('default', $entityManager));
```

## 🗄️ Multi-Database Setup

For multi-database setups, register multiple services with distinct names and configure a storage mapper:

```php
<?php

use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;

// $appEntityManager reads/writes your application data
// $auditEntityManager writes audit rows to a separate database

$provider->registerAuditingService(
    new AuditingService('app', $appEntityManager)
);

$provider->registerStorageService(
    new StorageService('audit', $auditEntityManager)
);
```

> [!NOTE]
> See [Multi-Database](multi-database.md) for a complete guide including the `storage_mapper` callback.

## 🔎 Retrieving Services

```php
// Get all registered auditing services
$auditingServices = $provider->getAuditingServices();

// Get all registered storage services
$storageServices = $provider->getStorageServices();

// Get the underlying EntityManager from a service
$em = $storageService->getEntityManager();
```

---

## ♻️ Long-Running Processes (Symfony Messenger Workers)

When running auditor inside **Symfony Messenger workers**, the EntityManager is reset between messages by Symfony's `services_resetter`. Without proper handling, only the first message is audited correctly — subsequent messages silently fail because:

- The `DoctrineSubscriber` would hold a stale EntityManager reference.
- The `DoctrineProvider` would reuse cached `PreparedStatement` objects bound to a closed connection.

`DoctrineProvider` implements `Symfony\Contracts\Service\ResetInterface`. Its `reset()` method clears the prepared statement cache and resets all subscriber transaction caches, ensuring clean state between messages.

### Wiring `kernel.reset` in Symfony

For Symfony to call `reset()` automatically between messages, tag `DoctrineProvider` with `kernel.reset`:

**With `autoconfigure: true` (recommended)**

If your service definition uses `autoconfigure: true`, Symfony detects `ResetInterface` and registers the tag automatically — no additional config needed.

**Manual tag**

```yaml
# config/services.yaml
services:
    DH\Auditor\Provider\Doctrine\DoctrineProvider:
        tags:
            - { name: kernel.reset, method: reset }
```

> [!NOTE]
> If you use `damienharper/auditor-bundle`, check the bundle's documentation for its service configuration — the tag may already be applied.

---

## Next Steps

- 🗄️ [Multi-Database Setup](multi-database.md)
- 🛠️ [Schema Management](schema.md)
- ⚙️ [Configuration Reference](configuration.md)
