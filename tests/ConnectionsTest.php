<?php

namespace Omnibridge\Eloquent\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\SQLiteConnection;
use Omnibridge\Eloquent\Connections;
use PHPUnit\Framework\TestCase;

/**
 * The connection with or without Laravel: a database URL read as Illuminate's configuration, Capsule's
 * connection from it, a connection on an open PDO, and a Laravel database manager's (Capsule's manager is one:
 * the same ConnectionResolverInterface `app('db')` is).
 */
final class ConnectionsTest extends TestCase
{
    public function testADatabaseUrlIsIlluminatesConfiguration(): void
    {
        self::assertSame(
            ['driver' => 'mysql', 'host' => 'database', 'port' => 3306, 'database' => 'app', 'username' => 'user', 'password' => 'p@ss', 'charset' => 'utf8mb4', 'prefix' => ''],
            Connections::config('mysql://user:p%40ss@database:3306/app?serverVersion=8.4&charset=utf8mb4'),
        );
        self::assertSame(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], Connections::config('sqlite:///:memory:'));
        self::assertSame(['driver' => 'sqlite', 'database' => '/var/data.db', 'prefix' => ''], Connections::config('sqlite:///var/data.db'));
        $this->expectException(\InvalidArgumentException::class);
        Connections::config('oracle://user@host/db');
    }

    public function testCapsulePdoAndLaravelGiveAConnection(): void
    {
        $capsule = Connections::url('sqlite:///:memory:');
        self::assertInstanceOf(SQLiteConnection::class, $capsule);
        self::assertSame(1, (int) $capsule->selectOne('select 1 as one')->one);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('create table t (id integer primary key)');
        $pdo->exec('insert into t values (7)');
        self::assertSame([7], array_map('intval', Connections::pdo($pdo)->table('t')->pluck('id')->all()), 'the same database as the PDO');

        $manager = new Capsule();
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'reads');
        self::assertSame($manager->getConnection('reads'), Connections::laravel($manager->getDatabaseManager(), 'reads'));
    }
}
