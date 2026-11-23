<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * String primary key that is not named "id".
 */
#[ORM\Entity]
#[ORM\Table(name: 'e2e_country')]
class Country implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(name: 'iso_code', type: Types::STRING, length: 2)]
    public string $isoCode;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $name;

    public function __construct(string $isoCode, string $name)
    {
        $this->isoCode = $isoCode;
        $this->name = $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
