<?php

namespace Crumbls\Importer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Crumbls\Importer\ImporterServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test configuration
        config(['importer.pipeline.state.path' => storage_path('pipeline')]);
    }

    protected function getPackageProviders($app)
    {
        return [
            ImporterServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Define environment setup
        $app['config']->set('importer.default', 'csv');
        $app['config']->set('importer.drivers.csv', [
            'driver' => 'csv',
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ]);
    }
}
