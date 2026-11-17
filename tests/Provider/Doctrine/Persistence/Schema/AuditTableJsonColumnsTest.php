<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Schema;

use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Deprecations\Deprecation;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Audit tables JSON columns are created and updated consistently.
 *
 * Audit tables used to be created with JSON columns while updating them expected JSONB ones: on PostgreSQL,
 * audit:schema:update converted them to JSONB. DBAL 4 then introspects JSONB columns as JSON columns carrying
 * the deprecated "jsonb" platform option, so that every later schema comparison triggered a deprecation.
 *
 * @internal
 */
#[Small]
final class AuditTableJsonColumnsTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    private const string JSONB_PLATFORM_OPTION_DEPRECATION = 'https://github.com/doctrine/dbal/pull/6939';

    public function testUpdateAuditSchemaIsIdempotent(): void
    {
        $this->assertSame([], $this->getUpdateAuditSchemaSql(), 'Freshly created audit tables are up to date.');
    }

    public function testJsonColumnsAreCreatedAsJsonbOnPostgreSQL(): void
    {
        $connection = $this->getStorageConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->markTestSkipped('PostgreSQL only.');
        }

        $this->assertSame(['diffs' => 'jsonb', 'extra_data' => 'jsonb'], $this->getJsonColumnTypes($connection));
    }

    public function testJsonColumnsAreConvertedToJsonbOnceWithoutDeprecationOnPostgreSQL(): void
    {
        $connection = $this->getStorageConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->markTestSkipped('PostgreSQL only.');
        }

        // Audit table created by a previous version of the library
        $connection->executeStatement('ALTER TABLE author_audit ALTER diffs TYPE JSON, ALTER extra_data TYPE JSON');

        $sqls = $this->getUpdateAuditSchemaSql();
        $this->assertCount(2, $sqls);
        foreach ($sqls as $sql) {
            $this->assertMatchesRegularExpression('#^ALTER TABLE author_audit ALTER (diffs|extra_data) TYPE JSONB$#', $sql);
        }

        new SchemaManager($this->provider)->updateAuditSchema();
        $this->assertSame(['diffs' => 'jsonb', 'extra_data' => 'jsonb'], $this->getJsonColumnTypes($connection));

        $triggered = Deprecation::getTriggeredDeprecations()[self::JSONB_PLATFORM_OPTION_DEPRECATION] ?? 0;
        $this->assertSame([], $this->getUpdateAuditSchemaSql(), 'JSONB columns are up to date.');
        $this->assertSame(
            $triggered,
            Deprecation::getTriggeredDeprecations()[self::JSONB_PLATFORM_OPTION_DEPRECATION] ?? 0,
            'Comparing JSONB audit columns does not trigger the "jsonb" column platform option deprecation.'
        );
    }

    private function getStorageConnection(): Connection
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
    }

    /**
     * @return list<string>
     */
    private function getUpdateAuditSchemaSql(): array
    {
        return array_merge(...array_values(new SchemaManager($this->provider)->getUpdateAuditSchemaSql()));
    }

    /**
     * @return array<string, string>
     */
    private function getJsonColumnTypes(Connection $connection): array
    {
        $types = $connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'author_audit' AND column_name IN ('diffs', 'extra_data')"
        );
        ksort($types);

        return $types;
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
        ]);
    }
}
