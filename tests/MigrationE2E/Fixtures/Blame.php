<?php

declare(strict_types=1);

namespace DH\Auditor\Tests\MigrationE2E\Fixtures;

/**
 * Who the scenario acts as: read by the user, security and extra data providers of the generator.
 */
final class Blame
{
    /** @var null|array{0: string, 1: string} user id and username, null for an anonymous change */
    public static ?array $user = null;

    public static ?string $ip = null;

    public static ?string $firewall = null;

    /** @var null|array<string, mixed> */
    public static ?array $extraData = null;

    /**
     * @param null|array{0: string, 1: string} $user
     * @param null|array<string, mixed>        $extraData
     */
    public static function as(?array $user, ?string $ip = null, ?string $firewall = null, ?array $extraData = null): void
    {
        self::$user = $user;
        self::$ip = $ip;
        self::$firewall = $firewall;
        self::$extraData = $extraData;
    }
}
