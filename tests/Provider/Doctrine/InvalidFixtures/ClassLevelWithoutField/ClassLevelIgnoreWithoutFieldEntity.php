<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\ClassLevelWithoutField;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Ignore;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'class_level_ignore_without_field_entity')]
#[Auditable]
#[Ignore]
class ClassLevelIgnoreWithoutFieldEntity
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
