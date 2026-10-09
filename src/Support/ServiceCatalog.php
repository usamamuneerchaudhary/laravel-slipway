<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Service;

/**
 * Known backing services. Credentials here are throwaway values for ephemeral CI
 * containers that are destroyed after each run; they protect nothing real.
 *
 * "{host}" in appEnv is replaced by each driver (127.0.0.1 or the service alias).
 */
final class ServiceCatalog
{
    /** @var array<string,Service>|null */
    private static ?array $services = null;

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    public static function get(string $name): Service
    {
        return self::all()[$name] ?? throw new InvalidArgumentException("Unknown service [{$name}].");
    }

    /** @return array<string,Service> */
    private static function all(): array
    {
        return self::$services ??= [
            'mysql' => new Service(
                name: 'mysql',
                image: 'mysql:8.4',
                env: ['MYSQL_ROOT_PASSWORD' => 'password', 'MYSQL_DATABASE' => 'testing'],
                ports: [3306],
                appEnv: [
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => '{host}',
                    'DB_PORT' => '3306',
                    'DB_DATABASE' => 'testing',
                    'DB_USERNAME' => 'root',
                    'DB_PASSWORD' => 'password',
                ],
                healthCmd: 'mysqladmin ping -h 127.0.0.1 -uroot -ppassword',
            ),
            'postgres' => new Service(
                name: 'postgres',
                image: 'postgres:17',
                env: ['POSTGRES_PASSWORD' => 'password', 'POSTGRES_DB' => 'testing'],
                ports: [5432],
                appEnv: [
                    'DB_CONNECTION' => 'pgsql',
                    'DB_HOST' => '{host}',
                    'DB_PORT' => '5432',
                    'DB_DATABASE' => 'testing',
                    'DB_USERNAME' => 'postgres',
                    'DB_PASSWORD' => 'password',
                ],
                healthCmd: 'pg_isready -U postgres',
            ),
            'redis' => new Service(
                name: 'redis',
                image: 'redis:7',
                ports: [6379],
                appEnv: [
                    'REDIS_HOST' => '{host}',
                    'REDIS_PORT' => '6379',
                ],
                healthCmd: 'redis-cli ping',
            ),
        ];
    }
}
