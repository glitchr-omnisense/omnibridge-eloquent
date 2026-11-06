<?php

namespace Omnibridge\Eloquent;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

/**
 * Eloquent's database connection, with a framework or without one: the store
 * (EntityStore) reads through an Illuminate\Database\Connection, whoever made it.
 *
 * - inside Laravel, the application's own connection (laravel(): `app('db')`,
 *   a connection's name or the default one);
 * - outside Laravel, Capsule (capsule(): Illuminate's configuration array;
 *   url(): a DATABASE_URL as Doctrine and Symfony write it);
 * - beside another persistence, its PDO (pdo()): the same connection, so the
 *   same transaction - what a comparison of two backends on one database needs.
 */
final class Connections
{
    /**
     * A connection made by Capsule, Eloquent's manager outside Laravel.
     *
     * @param array<string, mixed> $config Illuminate's configuration of a connection (driver, host, database, username...)
     */
    public static function capsule(array $config, string $name = 'default'): Connection
    {
        $capsule = new Capsule();
        $capsule->addConnection($config, $name);

        return $capsule->getConnection($name);
    }

    /**
     * A connection made by Capsule from a database URL: mysql://user:password@host:3306/name?charset=utf8mb4,
     * sqlite:///path/to/file.db (sqlite:///:memory: in memory), pgsql://... The query string's keys are added to the
     * configuration (charset, collation, serverVersion is ignored).
     */
    public static function url(string $url, string $name = 'default'): Connection
    {
        return self::capsule(self::config($url), $name);
    }

    /**
     * The configuration array of a database URL (url()).
     *
     * @return array<string, mixed>
     */
    public static function config(string $url): array
    {
        if (preg_match('#^sqlite3?:///(.*)$#', $url, $sqlite)) {
            $path = explode('?', $sqlite[1], 2)[0];

            return ['driver' => 'sqlite', 'database' => '' === $path || ':memory:' === $path || '/:memory:' === $path ? ':memory:' : '/'.ltrim($path, '/'), 'prefix' => ''];
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException('A database URL needs a scheme: mysql://, pgsql://, sqlite:///...');
        }
        $driver = match (strtolower($parts['scheme'])) {
            'mysql', 'mysql2', 'pdo-mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql', 'pdo-pgsql' => 'pgsql',
            default => throw new \InvalidArgumentException(\sprintf('No Eloquent driver for the scheme "%s".', $parts['scheme'])),
        };
        parse_str($parts['query'] ?? '', $query);
        unset($query['serverVersion']);

        return array_filter([
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? null,
            'database' => ltrim(rawurldecode($parts['path'] ?? ''), '/'),
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'charset' => $query['charset'] ?? ('mysql' === $driver ? 'utf8mb4' : 'utf8'),
            'prefix' => '',
        ] + $query, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * A connection on a PDO that is already open - Doctrine's (`$connection->getNativeConnection()`), a test's: the
     * same database and the same transaction as whoever opened it.
     */
    public static function pdo(\PDO $pdo, string $database = ''): Connection
    {
        return match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => new MySqlConnection($pdo, $database, '', ['driver' => 'mysql', 'database' => $database, 'name' => 'omnibridge']),
            'sqlite' => new SQLiteConnection($pdo, $database, '', ['driver' => 'sqlite', 'database' => $database, 'name' => 'omnibridge']),
            'pgsql' => new PostgresConnection($pdo, $database, '', ['driver' => 'pgsql', 'database' => $database, 'name' => 'omnibridge']),
            default => throw new \InvalidArgumentException(\sprintf('No Eloquent connection for the PDO driver "%s".', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))),
        };
    }

    /**
     * The connection of a Laravel application: its database manager (`app('db')`), the connection named or the
     * default one.
     */
    public static function laravel(ConnectionResolverInterface $database, ?string $name = null): Connection
    {
        $connection = $database->connection($name);
        if (!$connection instanceof Connection) {
            throw new \InvalidArgumentException(\sprintf('The connection "%s" is no Illuminate\Database\Connection (%s).', $name ?? 'default', get_debug_type($connection)));
        }

        return $connection;
    }
}
