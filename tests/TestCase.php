<?php

namespace Crumbls\Importer\Tests;

use Crumbls\Importer\ImporterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            ImporterServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('importer.models', [
            'import' => \Crumbls\Importer\Models\Import::class,
        ]);
        
        // Set up auth config with a class that exists
        $app['config']->set('auth.providers.users.model', \App\Models\User::class);
    }
}