<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single table inheritance: Car and Bike share the vehicle table, and so one audit table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_vehicle')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['car' => Car::class, 'bike' => Bike::class])]
abstract class Vehicle implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        public string $label
    ) {}

    public function __toString(): string
    {
        return $this->label;
    }
}
