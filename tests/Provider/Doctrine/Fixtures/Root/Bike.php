<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Root;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Bike extends Vehicle {}
