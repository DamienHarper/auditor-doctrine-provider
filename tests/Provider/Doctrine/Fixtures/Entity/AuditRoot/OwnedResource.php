<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\AuditRoot;

use DH\Auditor\Attribute\AuditRoot;

#[AuditRoot('owner')]
abstract class OwnedResource {}
