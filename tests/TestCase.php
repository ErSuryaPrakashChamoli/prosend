<?php

namespace Tests;

use App\Services\Deliverability\DnsResolver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Fakes\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run unless tests use the in-memory SQLite database from phpunit.xml. A cached configuration
     * (bootstrap/cache/config.php) silently overrides phpunit.xml, and refreshing the database would then
     * erase the real one.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests must use the in-memory SQLite database, but they are configured for [{$connection}: {$database}]"
                .($app->configurationIsCached() ? ' because the configuration is cached. Run "php artisan config:clear" first.' : '.')
                .' Refusing to run, because refreshing the database would erase it.'
            );
        }

        return $app;
    }

    /**
     * No test may reach real DNS. Tests that need answers install their own FakeDnsResolver.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
    }
}
