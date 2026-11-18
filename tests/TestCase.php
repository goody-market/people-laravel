<?php

declare(strict_types=1);

namespace Oki\People\Laravel\Tests;

use Oki\People\Laravel\PeopleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PeopleServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // The projection's tables need somewhere to be. In memory, so a
        // suite leaves nothing behind.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('people.issuer', ISSUER);
        $app['config']->set('people.client_id', CLIENT);
        $app['config']->set('people.client_secret', 'client-secret');
        $app['config']->set('people.audience', AUDIENCE);
        $app['config']->set('people.api_key', API_KEY);
        $app['config']->set('auth.guards.people', ['driver' => 'people']);
    }
}
