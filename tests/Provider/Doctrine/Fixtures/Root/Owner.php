<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root;

use Doctrine\ORM\Mapping as ORM;

/**
 * A root whose primary key is not named "id".
 */
#[ORM\Entity]
#[ORM\Table(name: 'root_owner')]
class Owner implements \Stringable
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 20)]
        public string $code,
        #[ORM\Column(length: 50)]
        public string $name,
    ) {}

    public function __toString(): string
    {
        return $this->name;
    }
}
