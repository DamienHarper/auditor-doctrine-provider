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

## Next Steps

- 🗄️ [Multi-Database Setup](multi-database.md)
- 🛠️ [Schema Management](schema.md)
- ⚙️ [Configuration Reference](configuration.md)
