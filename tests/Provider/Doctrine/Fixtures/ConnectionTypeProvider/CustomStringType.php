<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\ConnectionTypeProvider;

use Doctrine\DBAL\Types\StringType;

final class CustomStringType extends StringType {}
