<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Attribute\Mask;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mask_entity')]
#[Auditable]
#[Mask(strategy: Mask::KEYED_HASH, field: 'email')]
class MaskEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $name = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Mask]
    public ?string $pin = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Mask]
    #[DiffLabel(resolver: DummyCategoryResolver::class)]
    public int $categoryId = 0;

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
