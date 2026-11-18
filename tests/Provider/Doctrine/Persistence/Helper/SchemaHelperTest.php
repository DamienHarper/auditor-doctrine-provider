<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\Provider\Doctrine\Persistence\Helper;

use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class SchemaHelperTest extends TestCase
{
    /**
     * Every option of an audit table column definition is carried over to the built column: a lost option
     * makes audit:schema:update and the ORM schema tool (which uses the raw options) disagree on the column.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('provideBuildColumnKeepsEveryOptionOfTheAuditTableColumnsCases')]
    public function testBuildColumnKeepsEveryOptionOfTheAuditTableColumns(string $columnName, string $type, array $options): void
    {
        $column = SchemaHelper::buildColumn($columnName, $type, $options);

        $this->assertSame($type, $column->getTypeName());

        $actualOptions = [
            'autoincrement' => $column->getAutoincrement(),
            'default' => $column->getDefault(),
            'fixed' => $column->getFixed(),
            'length' => $column->getLength(),
            'notnull' => $column->getNotnull(),
            'platformOptions' => array_filter(
                ['charset' => $column->getCharset(), 'collation' => $column->getCollation()],
                static fn (?string $value): bool => null !== $value,
            ),
            'unsigned' => $column->getUnsigned(),
        ];

        foreach ($options as $option => $value) {
            $this->assertArrayHasKey($option, $actualOptions, \sprintf('The "%s" option of the "%s" column is checked.', $option, $columnName));
            $this->assertSame($value, $actualOptions[$option], \sprintf('The "%s" option of the "%s" column is kept.', $option, $columnName));
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function provideBuildColumnKeepsEveryOptionOfTheAuditTableColumnsCases(): iterable
    {
        $platformOptionsCases = [
            'no platform options' => [],
            'charset and collation' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ];

        foreach ($platformOptionsCases as $case => $platformOptions) {
            foreach (SchemaHelper::getAuditTableColumns($platformOptions) as $columnName => $struct) {
                yield $columnName.', '.$case => [$columnName, $struct['type'], $struct['options']];
            }
        }
    }
}
