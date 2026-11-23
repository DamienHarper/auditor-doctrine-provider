<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'root_vehicle')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['car' => Car::class, 'bike' => Bike::class])]
abstract class Vehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 50)]
        public string $label,
        #[ORM\ManyToOne(targetEntity: Owner::class)]
        #[ORM\JoinColumn(referencedColumnName: 'code', nullable: true)]
        public ?Owner $owner = null,
        #[ORM\ManyToOne(targetEntity: Zone::class)]
        #[ORM\JoinColumn(referencedColumnName: 'region', nullable: true)]
        public ?Zone $zone = null,
    ) {}
}
