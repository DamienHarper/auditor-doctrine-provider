<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Bike extends Vehicle
{
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $gears = null;
}
