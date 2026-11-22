<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Helper;

use Doctrine\DBAL\Types\Types;

abstract class SchemaHelper
{
    private const string COMPOSITE_INDEX_PREFIX = '__composite_';

    /**
     * Return columns of audit tables.
     *
     * @return array{id: array{type: string, options: array{autoincrement: true, unsigned: true}}, type: array{type: string, options: array{notnull: true, length: int}}, object_id: array{type: string, options: array{notnull: true}}, discriminator: array{type: string, options: array{default: null, notnull: false}}, transaction_hash: array{type: string, options: array{notnull: false, length: int}}, diffs: array{type: string, options: array{default: null, notnull: false}}, extra_data: array{type: string, options: array{default: null, notnull: false}}, blame_id: array{type: string, options: array{default: null, notnull: false}}, blame_user: array{type: string, options: array{default: null, notnull: false, length: int}}, blame_user_fqdn: array{type: string, options: array{default: null, notnull: false, length: int}}, blame_user_firewall: array{type: string, options: array{default: null, notnull: false, length: int}}, ip: array{type: string, options: array{default: null, notnull: false, length: int}}, created_at: array{type: string, options: array{notnull: true}}}
     */
    public static function getAuditTableColumns(array $defaultTableOptions = []): array
    {
        return [
            'id' => [
                'type' => Types::INTEGER,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 10,
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
            'transaction_hash' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length' => 40,
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
            'blame_user' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'blame_user_fqdn' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'blame_user_firewall' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 100,
                    'platformOptions' => $defaultTableOptions,
                ],
            ],
            'ip' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 45,
                    'platformOptions' => $defaultTableOptions,
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
     * Single-column entries are keyed by their column name.
     * Composite entries are keyed by a '__composite_*' synthetic key with an explicit 'columns' list.
     *
     * @return array{id: array{type: string}, type: array{type: string, name: string}, object_id: array{type: string, name: string}, discriminator: array{type: string, name: string}, transaction_hash: array{type: string, name: string}, blame_id: array{type: string, name: string}, created_at: array{type: string, name: string}, __composite_blame_user_blame_id: array{type: string, name: string, columns: non-empty-list<string>}}
     */
    public static function getAuditTableIndices(string $tablename): array
    {
        return [
            'id' => [
                'type' => 'primary',
            ],
            'type' => [
                'type' => 'index',
                'name' => 'type_'.md5($tablename).'_idx',
            ],
            'object_id' => [
                'type' => 'index',
                'name' => 'object_id_'.md5($tablename).'_idx',
            ],
            'discriminator' => [
                'type' => 'index',
                'name' => 'discriminator_'.md5($tablename).'_idx',
            ],
            'transaction_hash' => [
                'type' => 'index',
                'name' => 'transaction_hash_'.md5($tablename).'_idx',
            ],
            'blame_id' => [
                'type' => 'index',
                'name' => 'blame_id_'.md5($tablename).'_idx',
            ],
            'created_at' => [
                'type' => 'index',
                'name' => 'created_at_'.md5($tablename).'_idx',
            ],
            // Covers the user filter of the viewer (SELECT DISTINCT blame_id, blame_user ... ORDER BY blame_user)
            // without reading the table rows.
            self::COMPOSITE_INDEX_PREFIX.'blame_user_blame_id' => [
                'type' => 'index',
                'columns' => ['blame_user', 'blame_id'],
                'name' => 'blame_user_blame_id_'.md5($tablename).'_idx',
            ],
        ];
    }

    /**
     * Tells whether an audit table indices key is a synthetic key of a composite index.
     */
    public static function isCompositeIndexKey(string $key): bool
    {
        return str_starts_with($key, self::COMPOSITE_INDEX_PREFIX);
    }

    public static function isValidPayload(array $payload): bool
    {
        return array_all(array_keys(self::getAuditTableColumns()), static fn (string $columnName): bool => !('id' !== $columnName && !\array_key_exists($columnName, $payload)));
    }
}
