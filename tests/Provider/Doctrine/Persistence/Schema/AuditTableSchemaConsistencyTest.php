<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Schema;

use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The ORM schema tool (doctrine:schema:update, through CreateSchemaListener) and SchemaManager
 * (audit:schema:update) define audit tables the same way.
 *
 * Without the DBAL schema editor API, CreateSchemaListener builds the audit table from the raw column options,
 * while SchemaManager builds its columns with SchemaHelper::buildColumn(). The latter used to drop the "fixed"
 * option: transaction_id was a CHAR(26) column for the ORM schema tool and a VARCHAR(26) one for SchemaManager,
 * and each tool reverted the change of the other.
 *
 * @internal
 */
#[Small]
final class AuditTableSchemaConsistencyTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    /**
     * Disabling the schema editor API, as an application still on the ORM default does, is deprecated.
     */
    private const string SCHEMA_EDITOR_API_DEPRECATION = '^Disabling the schema editor API is deprecated';

    #[DataProvider('provideSchemaEditorApiCases')]
    #[IgnoreDeprecations(self::SCHEMA_EDITOR_API_DEPRECATION)]
    public function testOrmSchemaToolKeepsAuditTablesUpdatedBySchemaManager(bool $useSchemaEditorApi): void
    {
        $this->useSchemaEditorApi($useSchemaEditorApi);

        // setUp() created the audit table, then updated it with SchemaManager
        $this->assertTrue($this->getStorageEntityManager()->getConnection()->createSchemaManager()->tableExists('author_audit'));
        $this->assertSame([], $this->getOrmUpdateAuditTablesSql(), 'The ORM schema tool does not change the audit tables.');
    }

    #[DataProvider('provideSchemaEditorApiCases')]
    #[IgnoreDeprecations(self::SCHEMA_EDITOR_API_DEPRECATION)]
    public function testSchemaManagerKeepsAuditTablesUpdatedByOrmSchemaTool(bool $useSchemaEditorApi): void
    {
        $this->useSchemaEditorApi($useSchemaEditorApi);

        $entityManager = $this->getStorageEntityManager();
        new SchemaTool($entityManager)->updateSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $sqls = array_merge(...array_values(new SchemaManager($this->provider)->getUpdateAuditSchemaSql()));
        $this->assertSame([], $sqls, 'SchemaManager does not change the audit tables.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideSchemaEditorApiCases(): iterable
    {
        yield 'with the schema editor API' => [true];

        yield 'without the schema editor API' => [false];
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
        ]);
    }

    private function useSchemaEditorApi(bool $useSchemaEditorApi): void
    {
        $configuration = $this->getStorageEntityManager()->getConfiguration();
        if (!method_exists($configuration, 'setUseSchemaEditorApi')) {
            if ($useSchemaEditorApi) {
                $this->markTestSkipped('The ORM does not support the schema editor API.');
            }

            return;
        }

        $configuration->setUseSchemaEditorApi($useSchemaEditorApi);
    }

    private function getStorageEntityManager(): EntityManagerInterface
    {
        return $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager();
    }

    /**
     * @return list<string>
     */
    private function getOrmUpdateAuditTablesSql(): array
    {
        $entityManager = $this->getStorageEntityManager();
        $sqls = new SchemaTool($entityManager)->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());

        return array_values(array_filter($sqls, static fn (string $sql): bool => str_contains($sql, '_audit')));
    }
}
