<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Issues;

use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue132\AbstractParentEntity;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Issue132\DummyEntity;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class Issue132Test extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testIssue132(): void
    {
        $manager = new SchemaManager($this->provider);
        $schema = $manager->createAuditTable(DummyEntity::class);
        $this->assertTrue($schema->hasTable('parent_entities_audit'));
    }

    public function testIssue132SchemaListener(): void
    {
        $em = array_values($this->provider->getAuditingServices())[0]->getEntityManager();
        $meta = $em->getClassMetadata(AbstractParentEntity::class);
        $listener = new CreateSchemaListener($this->provider);
        $schema = new Schema();
        $tableName = $meta->getTableName();
        $args = new GenerateSchemaTableEventArgs($meta, $schema, new Table($tableName));
        $listener->postGenerateSchemaTable($args);

        $manager = new SchemaManager($this->provider);
        $auditTableName = $manager->computeAuditTablename($tableName, $this->provider->getConfiguration());
        // With the DBAL schema editor API, the listener hands a new schema back to the ORM
        $this->assertTrue($args->getSchema()->hasTable($auditTableName));
    }

    /**
     * When the ORM does not generate the schema with the schema editor API, it keeps mutating the schema it
     * passed to listeners: the audit table must be added to that very schema.
     *
     * The mutable DBAL schema API is deprecated as of DBAL 4.5; the ORM schema tool silences those
     * deprecations itself in that mode, this test calls the listener directly.
     */
    #[IgnoreDeprecations]
    public function testIssue132SchemaListenerWithoutSchemaEditorApi(): void
    {
        $em = array_values($this->provider->getAuditingServices())[0]->getEntityManager();
        $configuration = $em->getConfiguration();
        if (!method_exists($configuration, 'setUseSchemaEditorApi')) {
            $this->markTestSkipped('The ORM does not support the schema editor API.');
        }

        $configuration->setUseSchemaEditorApi(false);

        $meta = $em->getClassMetadata(AbstractParentEntity::class);
        $listener = new CreateSchemaListener($this->provider);
        $schema = new Schema();
        $tableName = $meta->getTableName();
        $args = new GenerateSchemaTableEventArgs($meta, $schema, new Table($tableName));
        $listener->postGenerateSchemaTable($args);

        $manager = new SchemaManager($this->provider);
        $auditTableName = $manager->computeAuditTablename($tableName, $this->provider->getConfiguration());
        $this->assertSame($schema, $args->getSchema(), 'The schema has not been replaced.');
        $this->assertTrue($schema->hasTable($auditTableName));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            DummyEntity::class => ['enabled' => true],
        ]);
    }
}
