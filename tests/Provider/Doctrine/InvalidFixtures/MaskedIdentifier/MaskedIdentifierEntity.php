<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\MaskedIdentifier;

use DH\Auditor\Attribute\Auditable;
use DH\Auditor\Attribute\Mask;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kept out of the Fixtures directory: loading its attributes must fail (an identifier cannot be masked).
 */
#[ORM\Entity]
#[ORM\Table(name: 'masked_identifier_entity')]
#[Auditable]
class MaskedIdentifierEntity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Mask]
    private string $id;
}
