# Multi-Database Setup

> **Store audit entries in a separate database**

This guide explains how to configure the DoctrineProvider to store audit entries in a dedicated database, separate from your application database.

## 🔍 Why Multi-Database?

There are several reasons to store audits in a separate database:

- **Performance isolation** — Heavy audit write traffic doesn't affect your application DB
- **Security** — Restrict access to audit data independently from application data
- **Retention policies** — Apply different backup and archiving strategies to audits
- **Compliance** — Keep audit data in a separate system with stricter access controls

## 🏗️ Overview

Multi-database setups involve:

1. **One (or more) `AuditingService`(s)** wrapping the application EntityManager(s) (source of events)
2. **One (or more) `StorageService`(s)** wrapping the audit EntityManager(s)
3. **A `storage_mapper`** callback to route each entity's audits to the right storage service (required when more than one StorageService is registered)

## 🚀 Basic Multi-Database Setup

### Step 1: Configure Both EntityManagers

Set up two EntityManagers: one for your application, one for the audit database.

```php
<?php

// $appEntityManager  → reads/writes your application entities
// $auditEntityManager → writes audit rows to a separate database
```

### Step 2: Register Services

```php
<?php

use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;

$provider->registerAuditingService(
    new AuditingService('app', $appEntityManager)
);

$provider->registerStorageService(
    new StorageService('audit', $auditEntityManager)
);
```

### Step 3: Configure the Provider

Since there is only one storage service, no storage mapper is needed — all audits go to `'audit'` automatically:

```php
<?php

use DH\Auditor\Provider\Doctrine\Configuration;

$configuration = new Configuration([
    'table_suffix' => '_audit',
    'entities'     => [
        App\Entity\User::class => ['enabled' => true],
        App\Entity\Post::class => ['enabled' => true],
    ],
    // No storage_mapper needed when there is only one StorageService
]);
```

### Step 4: Update Audit Schema

```php
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;

(new SchemaManager($provider))->updateAuditSchema();
// Creates tables in the audit EntityManager's database
```

## 🔀 Multiple Storage Services with a Mapper

When you need different entities routed to different storage services:

```php
<?php

use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;

// Register the auditing service (application EntityManager)
$provider->registerAuditingService(
    new AuditingService('app', $appEntityManager)
);

// Register multiple storage services
$provider->registerStorageService(
    new StorageService('audit_sensitive', $auditSensitiveEntityManager)
);
$provider->registerStorageService(
    new StorageService('audit_general', $auditGeneralEntityManager)
);

$configuration = new Configuration([
    'entities' => [
        App\Entity\User::class    => ['enabled' => true],
        App\Entity\Payment::class => ['enabled' => true],
        App\Entity\Post::class    => ['enabled' => true],
    ],

    // Required when more than one StorageService is registered
    'storage_mapper' => function (string $entity, array $storageServices): StorageService {
        return match ($entity) {
            App\Entity\User::class,
            App\Entity\Payment::class => $storageServices['audit_sensitive'],
            default                   => $storageServices['audit_general'],
        };
    },
]);
```

> [!CAUTION]
> When more than one `StorageService` is registered, a `storage_mapper` **must** be provided. The provider throws a `ProviderException` if it cannot determine which storage service to use.

## 🔧 Setting the Mapper at Runtime

The storage mapper can also be set after the provider is created:

```php
use DH\Auditor\Provider\Doctrine\Service\StorageService;

$provider->getConfiguration()->setStorageMapper(
    function (string $entity, array $services): StorageService {
        return $services['audit_general'];
    }
);
```

## ⚠️ Transactions and Multi-Database

> [!WARNING]
> When using separate databases, the audit write and the application write happen in **different** database connections. If the application transaction is rolled back after an audit entry is written, the audit entry will **not** be rolled back. Design your application accordingly.

For strict transactional consistency, keep auditing and storage on the same EntityManager/connection.

---

## Next Steps

- 🛠️ [Schema Management](schema.md)
- 🔍 [Querying Audits](../../querying/index.md)
- ⚙️ [Configuration Reference](configuration.md)
