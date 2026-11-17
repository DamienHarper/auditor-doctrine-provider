<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Traits;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Gedmo\SoftDeleteable\SoftDeleteableListener;

trait EntityManagerInterfaceTrait
{
    use ConnectionTrait;

    private array $fixturesPath = [
        __DIR__.'/../../../../src/Provider/Doctrine/Auditing/Attribute',
        __DIR__.'/../Fixtures',
    ];

    private function createEntityManager(?array $paths = null, string $connectionName = 'default', ?array $params = null): EntityManagerInterface
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration($paths ?? $this->fixturesPath, true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));

        $configuration->enableNativeLazyObjects(true);

        // ORM 3.8+ with DBAL 4.5+: generate schemas with the schema editor API, as ORM 4 will require.
        // Without it, ORM's SchemaTool silences DBAL schema-mutation deprecations process-wide, which
        // would hide the ones triggered by this library.
        if (method_exists($configuration, 'setUseSchemaEditorApi') && method_exists(Schema::class, 'edit')) {
            $configuration->setUseSchemaEditorApi(true);
        }

        $connection = $this->getConnection($connectionName, $params);

        $em = new EntityManager($connection, $configuration);
        $evm = $em->getEventManager();

        // Attach SoftDeleteableListener to the EventManager
        $evm->addEventListener(Events::onFlush, new SoftDeleteableListener());

        return $em;
    }
}
