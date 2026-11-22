<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Attribute\Ignore;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'class_level_attributes_entity')]
#[Auditable]
#[Ignore(field: 'secret')]
#[Ignore(field: 'token')]
#[DiffLabel(resolver: DummyCategoryResolver::class, field: 'categoryId')]
class ClassLevelAttributesEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $secret = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $token = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Ignore]
    public string $note = '';

    #[ORM\Column(type: Types::INTEGER)]
    public int $categoryId = 0;

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
