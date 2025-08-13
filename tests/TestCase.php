<?php

namespace Crumbls\Importer\Tests;

use Crumbls\Importer\ImporterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Run database migrations for testing
        $this->loadLaravelMigrations();
        $this->artisan('migrate', ['--database' => 'testing']);
        
        // Create queue tables for testing
        $this->createQueueTables();
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
        
        // Set up auth config with a class that exists in the test environment
        $app['config']->set('auth.providers.users.model', \App\Models\User::class);
        
        // Set up composer autoloader for test models
        $app['files']->requireOnce(__DIR__ . '/Models/User.php');
    }

    protected function createQueueTables(): void
    {
        if (!$this->app['db']->getSchemaBuilder()->hasTable('jobs')) {
            $this->app['db']->getSchemaBuilder()->create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!$this->app['db']->getSchemaBuilder()->hasTable('failed_jobs')) {
            $this->app['db']->getSchemaBuilder()->create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->nullable()->unique();
                $table->text('connection')->nullable();
                $table->text('queue')->nullable();
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }
}