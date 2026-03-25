<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audited_entity')]
#[Auditable]
class AuditedEntity
{
    public string $auditedField;

    #[Ignore]
    public string $ignoredField;

    #[Ignore]
    protected string $ignoredProtectedField;

    #[Ignore]
    private string $ignoredPrivateField;

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
