<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root;

use Doctrine\ORM\Mapping as ORM;

/**
 * A root whose primary key is a backed enum.
 */
#[ORM\Entity]
#[ORM\Table(name: 'root_zone')]
class Zone
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 10, enumType: Region::class)]
        public Region $region,
    ) {}
}
