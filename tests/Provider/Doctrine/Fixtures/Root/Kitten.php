<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'root_kitten')]
class Kitten extends Pet
{
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $color = null;
}
