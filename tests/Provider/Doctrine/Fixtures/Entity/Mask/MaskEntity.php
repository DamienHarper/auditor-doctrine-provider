<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Mask;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\DiffLabel;
use DH\Auditor\Attribute\Mask;
use DH\Auditor\Masking\MaskStrategy;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Attribute\DummyCategoryResolver;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kept out of the Attribute directory: its keyed hash needs a mask secret, which the tests loading that directory do
 * not set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mask_entity')]
#[Auditable]
#[Mask(strategy: MaskStrategy::KeyedHash, field: 'email')]
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
