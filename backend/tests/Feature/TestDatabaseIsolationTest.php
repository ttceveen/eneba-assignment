<?php

namespace Tests\Feature;

use PDO;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_actual_connection_is_sqlite_in_memory(): void
    {
        $connection = $this->app['db']->connection();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('sqlite', $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertSame('', $connection->select('PRAGMA database_list')[0]->file);
    }

    public function test_inherited_environment_and_urls_are_overridden(): void
    {
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('sqlite', getenv('DB_CONNECTION'));
        $this->assertSame(':memory:', getenv('DB_DATABASE'));
        $this->assertSame('', getenv('DATABASE_URL'));
        $this->assertSame('', getenv('DB_URL'));
        $this->assertSame('sqlite', $_SERVER['DB_CONNECTION']);
        $this->assertSame('', $_SERVER['DATABASE_URL']);
    }

    public function test_non_testing_application_is_rejected(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tests require APP_ENV=testing');
        $this->assertSafeTestDatabase($this->app);
    }

    public function test_secondary_postgres_connection_is_rejected_before_connecting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database');
        $this->app['db']->connection('pgsql');
    }

    public function test_file_based_sqlite_is_rejected_before_connecting(): void
    {
        $this->app['config']->set('database.connections.unsafe', [
            'driver' => 'sqlite', 'database' => '/must-not-create-test-database.sqlite',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database');
        $this->app['db']->connection('unsafe');
    }

    public function test_unsafe_default_connection_is_rejected(): void
    {
        $this->app['config']->set('database.default', 'pgsql');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database');
        $this->assertSafeTestDatabase($this->app);
    }
}
