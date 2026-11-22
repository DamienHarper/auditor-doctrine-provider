<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\PropertyLevelWithField;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\DummyCategoryResolver;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'property_level_diff_label_with_field_entity')]
#[Auditable]
class PropertyLevelDiffLabelWithFieldEntity
{
    #[ORM\Column(type: Types::INTEGER)]
    #[DiffLabel(resolver: DummyCategoryResolver::class, field: 'other')]
    public int $categoryId = 0;

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
