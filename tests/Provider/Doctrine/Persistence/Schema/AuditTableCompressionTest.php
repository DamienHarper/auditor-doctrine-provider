<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Schema;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\JsonFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The "compression" option stores the large JSON columns of audit tables (diffs, extra_data) compressed on
 * MariaDB, and has no effect on other platforms.
 *
 * @internal
 */
#[Small]
final class AuditTableCompressionTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    private const string AUDIT_TABLE = 'author_audit';

    private const string COMPRESS_EXISTING_COLUMNS_SQL = 'ALTER TABLE `author_audit` MODIFY `diffs` JSON COMPRESSED DEFAULT NULL, MODIFY `extra_data` JSON COMPRESSED DEFAULT NULL';

    private const string DECOMPRESS_EXISTING_COLUMNS_SQL = 'ALTER TABLE `author_audit` MODIFY `diffs` JSON DEFAULT NULL, MODIFY `extra_data` JSON DEFAULT NULL';

    public function testCompressibleColumnsAreDeclaredCompressedOnMariaDBOnly(): void
    {
        $table = new SchemaManager($this->provider)->createAuditTable(Author::class, new Schema())->getTable(self::AUDIT_TABLE);
        $expected = $this->isMariaDB() ? 'JSON COMPRESSED DEFAULT NULL' : null;

        $this->assertSame($expected, $table->getColumn('diffs')->getColumnDefinition());
        $this->assertSame($expected, $table->getColumn('extra_data')->getColumnDefinition());
        $this->assertNull($table->getColumn('blame')->getColumnDefinition(), 'Only diffs and extra_data are compressed.');
    }

    public function testCompressibleColumnsAreCreatedCompressedOnMariaDB(): void
    {
        $this->skipUnlessMariaDB();

        $this->assertSame(['diffs', 'extra_data'], $this->getCompressedColumns());
    }

    public function testUpdateAuditSchemaIsIdempotent(): void
    {
        $this->assertSame([], $this->getUpdateAuditSchemaSql(), 'Freshly created compressed audit tables are up to date.');
    }

    public function testExistingColumnsAreCompressedOnceOnMariaDB(): void
    {
        $this->skipUnlessMariaDB();

        // Audit table created before compression was enabled
        $this->getStorageConnection()->executeStatement(self::DECOMPRESS_EXISTING_COLUMNS_SQL);
        $this->assertSame([], $this->getCompressedColumns());

        $this->assertSame([self::COMPRESS_EXISTING_COLUMNS_SQL], $this->getUpdateAuditSchemaSql());

        new SchemaManager($this->provider)->updateAuditSchema();

        $this->assertSame(['diffs', 'extra_data'], $this->getCompressedColumns());
        $this->assertSame([], $this->getUpdateAuditSchemaSql(), 'Compressed columns are up to date.');
    }

    public function testColumnsAreDecompressedWhenCompressionIsDisabledOnMariaDB(): void
    {
        $this->skipUnlessMariaDB();

        $author = new Author();
        $author->setFullname('John Doe')->setEmail('john.doe@example.com');
        $entityManager = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $entityManager->persist($author);
        $entityManager->flush();

        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['compression' => false]));
        $this->configureEntities();

        $this->assertSame([self::DECOMPRESS_EXISTING_COLUMNS_SQL], $this->getUpdateAuditSchemaSql());

        new SchemaManager($this->provider)->updateAuditSchema();

        $this->assertSame([], $this->getCompressedColumns());
        $this->assertSame([], $this->getUpdateAuditSchemaSql(), 'Decompressed columns are up to date.');

        $entries = new Reader($this->provider)->createQuery(Author::class)->execute();
        $this->assertCount(1, $entries, 'Audit entries are kept.');
        $this->assertSame('John Doe', $entries[0]->getDiffs()['fullname']['new']);
    }

    public function testAuditEntriesAreReadAndFilteredFromCompressedColumns(): void
    {
        $this->provider->getAuditor()->getEventDispatcher()->addListener(
            LifecycleEvent::class,
            static function (LifecycleEvent $event): void {
                $payload = $event->getPayload();
                $payload['extra_data'] = json_encode(['team' => 'backend', 'note' => str_repeat('extra data ', 50)], JSON_THROW_ON_ERROR);
                $event->setPayload($payload);
            },
        );

        // values larger than MariaDB's column_compression_threshold (100 bytes) are actually compressed
        $fullname = str_repeat('Compressed ', 23);
        $author = new Author();
        $author->setFullname($fullname)->setEmail('compressed@example.com');
        $entityManager = $this->provider->getAuditingServiceForEntity(Author::class)->getEntityManager();
        $entityManager->persist($author);
        $entityManager->flush();

        $entries = new Reader($this->provider)
            ->createQuery(Author::class)
            ->addFilter(new JsonFilter('extra_data', 'team', 'backend'))
            ->execute()
        ;

        $this->assertCount(1, $entries);
        $this->assertSame($fullname, $entries[0]->getDiffs()['fullname']['new']);
        $this->assertSame('backend', $entries[0]->extraData['team']);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = $this->createDoctrineProvider($this->createProviderConfiguration(['compression' => true]));
    }

    private function isMariaDB(): bool
    {
        return $this->getStorageConnection()->getDatabasePlatform() instanceof MariaDBPlatform;
    }

    private function skipUnlessMariaDB(): void
    {
        if (!$this->isMariaDB()) {
            $this->markTestSkipped('MariaDB only.');
        }
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
     * @return list<string>
     */
    private function getCompressedColumns(): array
    {
        return $this->getStorageConnection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_type LIKE '%COMPRESSED%' ORDER BY column_name",
            [self::AUDIT_TABLE],
        );
    }

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
        ]);
    }
}
