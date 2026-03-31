<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Helper;

use Doctrine\DBAL\Types\Types;

abstract class SchemaHelper
{
    /**
     * Return columns of audit tables.
     *
     * @return array<string, array{type: string, options: array<string, mixed>}>
     */
    public static function getAuditTableColumns(array $defaultTableOptions = []): array
    {
        return [
            'id' => [
                'type' => Types::BIGINT,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'schema_version' => [
                'type' => Types::SMALLINT,
                'options' => [
                    'notnull' => true,
                    'default' => 2,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 11,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'object_id' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'discriminator' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'transaction_id' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length' => 26,
                    'fixed' => true,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'diffs' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'extra_data' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_id' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'blame' => [
                'type' => DoctrineHelper::jsonStringType(),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'created_at' => [
                'type' => Types::DATETIME_IMMUTABLE,
                'options' => [
                    'notnull' => true,
                ],
            ],
        ];
    }

    /**
     * Return indices of an audit table.
     *
     * Single-column entries: keyed by the column name.
     * Composite entries: keyed by a '__composite_*' synthetic key with an explicit 'columns' list.
     *
     * @return array<string, array{type: string, name: string, columns?: list<string>}|array{type: string}>
     */
    public static function getAuditTableIndices(string $tablename): array
    {
        $hash = md5($tablename);

        return [
            'id' => [
                'type' => 'primary',
            ],
            'type' => [
                'type' => 'index',
                'name' => 'type_'.$hash.'_idx',
            ],
            'object_id' => [
                'type' => 'index',
                'name' => 'object_id_'.$hash.'_idx',
            ],
            'discriminator' => [
                'type' => 'index',
                'name' => 'discriminator_'.$hash.'_idx',
            ],
            'transaction_id' => [
                'type' => 'index',
                'name' => 'transaction_id_'.$hash.'_idx',
            ],
            'blame_id' => [
                'type' => 'index',
                'name' => 'blame_id_'.$hash.'_idx',
            ],
            'created_at' => [
                'type' => 'index',
                'name' => 'created_at_'.$hash.'_idx',
            ],
            // Composite indexes
            '__composite_object_id_type' => [
                'type' => 'index',
                'columns' => ['object_id', 'type'],
                'name' => 'object_id_type_'.$hash.'_idx',
            ],
            '__composite_blame_id_created_at' => [
                'type' => 'index',
                'columns' => ['blame_id', 'created_at'],
                'name' => 'blame_id_created_at_'.$hash.'_idx',
            ],
        ];
    }

    public static function isValidPayload(array $payload): bool
    {
        return array_all(array_keys(self::getAuditTableColumns()), static fn (string $columnName): bool => !('id' !== $columnName && !\array_key_exists($columnName, $payload)));
    }
}
