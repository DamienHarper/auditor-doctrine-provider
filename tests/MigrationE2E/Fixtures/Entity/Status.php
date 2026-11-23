<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures\Entity;

enum Status: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Banned = 'banned';
}
