<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // This runs before setUpTraits() can invoke RefreshDatabase.
        $app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->assertSafeConnection($event->connection);
        });
        $this->assertSafeTestDatabase($app);

        return $app;
    }

    protected function assertSafeTestDatabase(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Tests require APP_ENV=testing. No migrations were run.');
        }

        $connection = $app['db']->connection();
        $this->assertSafeConnection($connection);
        $pdo = $connection->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Tests require a SQLite PDO connection. No migrations were run.');
        }

        $databases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        if (! collect($databases)->contains(fn (array $database): bool => $database['name'] === 'main' && $database['file'] === '')) {
            throw new RuntimeException('Tests require an actual SQLite in-memory database. No migrations were run.');
        }
    }

    protected function assertSafeConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || $connection->getConfig('url')
            || $connection->getConfig('read')
            || $connection->getConfig('write')) {
            throw new RuntimeException('Unsafe test database: only SQLite :memory: is allowed. No migrations were run.');
        }
    }
}
