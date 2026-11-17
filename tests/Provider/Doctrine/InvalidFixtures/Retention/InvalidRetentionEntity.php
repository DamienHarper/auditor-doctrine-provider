<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\InvalidFixtures\Retention;

use DH\Auditor\Attribute\Auditable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kept out of the Fixtures directory: loading its metadata must fail.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invalid_retention_entity')]
#[Auditable(maxEntries: 0)]
class InvalidRetentionEntity
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;
}
