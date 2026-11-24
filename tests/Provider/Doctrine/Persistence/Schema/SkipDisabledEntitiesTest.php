<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Schema;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * With the "skip_disabled_entities" option, entities disabled by their configuration get no audit table, whichever
 * tool builds the schema (audit:schema:update or the ORM schema tool through CreateSchemaListener).
 *
 * @internal
 */
#[Small]
final class SkipDisabledEntitiesTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    public function testOptionIsDisabledByDefault(): void
    {
        $this->assertFalse($this->createProviderConfiguration()->isSkipDisabledEntitiesEnabled());
    }

    public function testDisabledEntitiesHaveNoAuditTable(): void
    {
        $this->assertTrue($this->provider->isAuditable(Post::class), 'Disabled entities stay auditable.');
        $this->assertFalse($this->provider->hasAuditTable(Post::class));
        $this->assertTrue($this->provider->hasAuditTable(Author::class));
        $this->assertTrue($this->provider->hasAuditTable(Comment::class), 'Entities without "enabled" key are enabled.');

        // tables built by the ORM schema tool (CreateSchemaListener) and by audit:schema:update during setUp()
        $schemaManager = $this->getStorageConnection()->createSchemaManager();
        $this->assertTrue($schemaManager->tablesExist(['author_audit']));
        $this->assertTrue($schemaManager->tablesExist(['comment_audit']));
        $this->assertFalse($schemaManager->tablesExist(['post_audit']), 'Disabled entities get no audit table.');
    }

    public function testUpdateAuditSchemaSkipsDisabledEntities(): void
    {
        $updater = new SchemaManager($this->provider);

        $this->assertSame([], array_merge(...array_values($updater->getUpdateAuditSchemaSql())), 'Nothing to create for disabled entities.');

        $tableNames = $updater->getAuditableTableNames($this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager());
        $this->assertArrayHasKey(Author::class, $tableNames);
        $this->assertArrayHasKey(Comment::class, $tableNames);
        $this->assertArrayNotHasKey(Post::class, $tableNames);
    }

    public function testDisabledEntitiesGetAnAuditTableWithoutTheOption(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration());
        $this->configureEntities();

        $this->assertTrue($this->provider->hasAuditTable(Post::class));

        $sqls = implode("\n", array_merge(...array_values(new SchemaManager($this->provider)->getUpdateAuditSchemaSql())));
        $this->assertStringContainsString('post_audit', $sqls, 'Disabled entities get an audit table by default.');
    }

    public function testAuditingCannotBeEnabledForSkippedEntities(): void
    {
        $configuration = $this->provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);

        $this->expectException(\LogicException::class);
        $configuration->enableAuditFor(Post::class);
    }

    public function testRuntimeChangesDoNotAffectAuditTables(): void
    {
        $configuration = $this->provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);

        $configuration->disableAuditFor(Author::class);

        $this->assertFalse($this->provider->isAudited(Author::class));
        $this->assertTrue($this->provider->hasAuditTable(Author::class), 'An entity disabled at runtime keeps its audit table.');

        $configuration->enableAuditFor(Author::class);
        $this->assertTrue($this->provider->isAudited(Author::class), 'It can be enabled again.');
    }

    public function testReaderRefusesSkippedEntities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Reader($this->provider)->createQuery(Post::class);
    }

    public function testReaderIgnoresSkippedEntitiesWhenReadingATransaction(): void
    {
        $this->assertSame([], new Reader($this->provider)->getAuditsByTransactionId('01JNOTATRANSACTIONID00000'));
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['skip_disabled_entities' => true]));
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => false],
            Comment::class => [],
        ]);
    }

    private function getStorageConnection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
    }
}
