<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\AuditRoot;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\AuditRoot;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_root_document')]
#[Auditable]
#[AuditRoot('folder')]
#[AuditRoot('folder.owner')]
#[AuditRoot('owner')]
class Document extends OwnedResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;
}
