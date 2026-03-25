<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use DH\Auditor\Attribute\Security;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'auditable_but_unaudited_entity')]
#[Auditable(enabled: false)]
#[Security(view: ['ROLE1', 'ROLE2'])]
class AuditableButUnauditedEntity
{
    public string $auditedField;

    #[Ignore]
    public string $ignoredField;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
