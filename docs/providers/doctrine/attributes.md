# Attributes Reference

> **Configure entity auditing with PHP 8 attributes**

Auditor uses PHP 8 attributes to configure Doctrine entity auditing. These attributes are defined in the `damienharper/auditor` core library and are shared across all providers.

## 📋 Overview

| Attribute       | Target   | Description                               |
|-----------------|----------|-------------------------------------------|
| `#[Auditable]`  | Class    | Marks an entity for auditing              |
| `#[Ignore]`     | Property | Excludes a property from auditing         |
| `#[Security]`   | Class    | Defines who can view entity audits        |
| `#[DiffLabel]`  | Property | Attaches a human-readable label resolver to a field |

## 📝 #[Auditable]

Marks a Doctrine entity as auditable.

### Namespace

```php
use DH\Auditor\Attribute\Auditable;
```

### Usage

```php
<?php

use DH\Auditor\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
class Post
{
    // Entity will be audited
}
```

### Parameters

| Parameter  | Type   | Default | Description                        |
|------------|--------|---------|------------------------------------|
| `enabled`  | `bool` | `true`  | Whether auditing is enabled        |

### Examples

```php
// Auditing enabled (default)
#[Auditable]
class Post {}

// Explicitly enabled
#[Auditable(enabled: true)]
class Post {}

// Disabled by default (can be enabled at runtime)
#[Auditable(enabled: false)]
class DraftPost {}
```

### Enabling/Disabling at Runtime

```php
$configuration = $provider->getConfiguration();

$configuration->disableAuditFor(Post::class);
$configuration->enableAuditFor(Post::class);
```

## 🚫 #[Ignore]

Excludes a property from being tracked in audits.

### Namespace

```php
use DH\Auditor\Attribute\Ignore;
```

### Usage

```php
<?php

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
class User
{
    #[ORM\Column(type: 'string')]
    private string $email = '';

    #[ORM\Column(type: 'string')]
    #[Ignore]  // Password changes won't be logged
    private string $password = '';

    #[ORM\Column(type: 'string', nullable: true)]
    #[Ignore]  // Tokens shouldn't be in audit logs
    private ?string $resetToken = null;
}
```

### Parameters

The `#[Ignore]` attribute has no parameters.

### Common Use Cases

```php
#[ORM\Entity]
#[Auditable]
class User
{
    // Security-sensitive fields
    #[Ignore]
    private string $password = '';

    #[Ignore]
    private ?string $salt = null;

    // Frequently changing but low-value fields
    #[Ignore]
    private int $loginCount = 0;

    #[Ignore]
    private ?\DateTimeInterface $lastActivityAt = null;
}
```

## 🔐 #[Security]

Defines which roles are allowed to view audits for an entity.

### Namespace

```php
use DH\Auditor\Attribute\Security;
```

### Usage

```php
<?php

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Security;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
#[Security(view: ['ROLE_ADMIN'])]
class User
{
    // Only users with ROLE_ADMIN can view User audits
}
```

### Parameters

| Parameter | Type            | Required | Description                              |
|-----------|-----------------|----------|------------------------------------------|
| `view`    | `array<string>` | Yes      | Array of roles allowed to view audits    |

### Examples

```php
// Single role
#[Security(view: ['ROLE_ADMIN'])]
class User {}

// Multiple roles (any of them grants access)
#[Security(view: ['ROLE_ADMIN', 'ROLE_AUDITOR', 'ROLE_COMPLIANCE'])]
class Order {}
```

### How It Works

The role checker callback is invoked when the `Reader` creates a query:

1. `Reader::createQuery()` is called for an entity
2. The role checker receives the entity class and `'view'` scope
3. If the role checker returns `false`, an `AccessDeniedException` is thrown

```php
$configuration->setRoleChecker(function (string $entity, string $scope) use ($provider): bool {
    $entities = $provider->getConfiguration()->getEntities();

    if (!isset($entities[$entity]['roles'][$scope])) {
        return true;
    }

    foreach ($entities[$entity]['roles'][$scope] as $role) {
        if ($this->currentUserHasRole($role)) {
            return true;
        }
    }

    return false;
});
```

## 🏷️ #[DiffLabel]

Without `#[DiffLabel]`, a change from category 1 to category 2 stores:

```json
{"categoryId": {"old": 1, "new": 2}}
```

With `#[DiffLabel]`, the same change stores:

```json
{"categoryId": {"old": {"value": 1, "label": "Books"}, "new": {"value": 2, "label": "Electronics"}}}
```

Labels are resolved at **write-time** and stored in the JSON — they remain accurate even if the referenced record is later renamed or deleted.

### Namespace

```php
use DH\Auditor\Attribute\DiffLabel;
```

### Usage

```php
<?php

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Auditable]
class Product
{
    #[ORM\Column(type: Types::INTEGER)]
    #[DiffLabel(resolver: CategoryResolver::class)]
    private int $categoryId;
}
```

The resolver must implement `DiffLabelResolverInterface`. Return `null` to fall back to storing the plain scalar:

```php
use DH\Auditor\Contract\DiffLabelResolverInterface;

final class CategoryResolver implements DiffLabelResolverInterface
{
    private const array LABELS = [1 => 'Books', 2 => 'Electronics', 3 => 'Clothing'];

    public function __invoke(mixed $value): ?string
    {
        return self::LABELS[$value] ?? null;
    }
}
```

### Parameters

| Parameter  | Type     | Required | Description                          |
|------------|----------|----------|--------------------------------------|
| `resolver` | `string` | Yes      | FQCN of the `DiffLabelResolverInterface` implementation |

### Wiring resolvers (standalone)

Resolvers are looked up at flush-time via a PSR-11 `ContainerInterface`. Inject it on the provider before flushing:

```php
use DH\Auditor\Provider\Doctrine\DoctrineProvider;

$provider = new DoctrineProvider($configuration);

$provider->setDiffLabelResolverLocator(new SimpleServiceLocator([
    CategoryResolver::class => fn () => new CategoryResolver(),
]));
```

Any PSR-11 container works. When using the Symfony bundle, the locator is wired automatically — see the [Diff Label Resolvers guide](https://github.com/DamienHarper/auditor-bundle/blob/master/docs/customization/diff-label-resolvers.md).

## 📄 Complete Example

```php
<?php

namespace App\Entity;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use DH\Auditor\Attribute\Security;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[Auditable]
#[Security(view: ['ROLE_ADMIN', 'ROLE_USER_AUDITOR'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(type: 'string')]
    #[Ignore]  // Don't audit password changes (security)
    private string $password = '';

    #[ORM\Column(type: 'string', nullable: true)]
    #[Ignore]  // Don't audit reset tokens
    private ?string $resetToken = null;

    #[ORM\Column(type: 'integer')]
    #[Ignore]  // Frequent updates, low audit value
    private int $loginCount = 0;
}
```

## 🔀 Combining with Programmatic Configuration

> [!TIP]
> Attributes can be combined with or overridden by programmatic configuration.

```php
use DH\Auditor\Provider\Doctrine\Configuration;

$configuration = new Configuration([
    'entities' => [
        // Override the Security attribute — more restrictive
        User::class => [
            'roles' => ['view' => ['ROLE_SUPER_ADMIN']],
        ],

        // Add additional ignored columns on top of attribute-defined ones
        Post::class => [
            'ignored_columns' => ['view_count'],
        ],

        // Completely disable auditing despite #[Auditable]
        DraftPost::class => [
            'enabled' => false,
        ],
    ],
]);
```

## ✅ Best Practices

1. **Always ignore sensitive data** — Passwords, tokens, secrets, salts
2. **Use `#[Security]` for sensitive entities** — Users, payments, orders, etc.
3. **Consider ignoring high-frequency low-value columns** — `last_activity_at`, counters
4. **Apply the principle of least privilege** — Start restrictive, relax as needed

> [!WARNING]
> Never audit password fields or authentication tokens. Always use `#[Ignore]` on all security-sensitive properties.

---

## Next Steps

- ⚙️ [Configuration Reference](configuration.md)
- 🔍 [Querying Audits](../../querying/index.md)
- 🏷️ [Diff Label Resolvers](https://github.com/DamienHarper/auditor-bundle/blob/master/docs/customization/diff-label-resolvers.md) — Full wiring guide (Symfony bundle)
