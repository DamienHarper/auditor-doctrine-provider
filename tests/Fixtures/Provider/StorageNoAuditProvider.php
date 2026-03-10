<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Fixtures\Provider;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\AbstractProvider;
use DH\Auditor\Provider\Doctrine\Configuration;

final class StorageNoAuditProvider extends AbstractProvider
{
    public function __construct()
    {
        $this->configuration = new Configuration([]);
    }

    public function persist(LifecycleEvent $event): void {}

    public function supportsStorage(): bool
    {
        return true;
    }

    public function supportsAuditing(): bool
    {
        return false;
    }
}
