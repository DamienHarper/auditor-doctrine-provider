<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures;

use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Author;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Bike;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Car;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Comment;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Country;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Post;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Tag;
use DH\Auditor\Tests\MigrationE2E\Fixtures\Entity\Translation;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Settings shared by the 1.x generator (tools/migration-e2e/v1) and the 2.x test, which run in two
 * processes against the same databases. Only depends on doctrine/dbal, available on both sides.
 *
 * - Primary storage: DATABASE_URL, or an SQLite file in the temporary directory.
 * - Secondary storage (Comment and Tag): always an SQLite file, so that the migration also goes through a
 *   second storage on another connection whatever the primary platform.
 */
final class Environment
{
    public const string PRIMARY = 'primary';

    public const string SECONDARY = 'secondary';

    /**
     * Audited entities and their options (the same on both sides).
     *
     * @return array<class-string, array<string, mixed>>
     */
    public static function entities(): array
    {
        return [
            Author::class => ['ignored_columns' => ['password']],
            Post::class => [],
            Comment::class => [],
            Tag::class => [],
            Car::class => [],
            Bike::class => [],
            Country::class => [],
            Translation::class => [],
        ];
    }

    /**
     * Entities whose audit entries are stored in the secondary storage.
     *
     * @return list<class-string>
     */
    public static function secondaryEntities(): array
    {
        return [Comment::class, Tag::class];
    }

    public static function entityPath(): string
    {
        return __DIR__.'/Entity';
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionParameters(string $storage): array
    {
        if (self::PRIMARY === $storage) {
            $url = getenv('DATABASE_URL');
            if (\is_string($url) && '' !== $url && !str_starts_with($url, 'sqlite:')) {
                return new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'])->parse($url);
            }
        }

        return ['driver' => 'pdo_sqlite', 'path' => self::sqlitePath($storage)];
    }

    public static function sqlitePath(string $storage): string
    {
        $path = getenv('E2E_'.mb_strtoupper($storage).'_SQLITE');

        return \is_string($path) && '' !== $path ? $path : sys_get_temp_dir().'/auditor-migration-e2e-'.$storage.'.sqlite';
    }

    /**
     * Where the generator writes the audit rows it produced, read back by the 2.x test.
     */
    public static function snapshotPath(): string
    {
        $path = getenv('E2E_SNAPSHOT');

        return \is_string($path) && '' !== $path ? $path : sys_get_temp_dir().'/auditor-migration-e2e-snapshot.json';
    }
}
