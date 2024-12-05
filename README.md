# Laravel Importer

A flexible, state-based importer package for Laravel with support for CSV, SQL, WordPress XML, and Eloquent data sources.

This will connect or read the remote datasource, determine the model(s) name(s), create migrations, then create standardized
data with seeders to handle importing it.

This is early beta and should not be used.  Forks appreciated.

Submit suggestions or requests to importer@crumbls.com

## Installation


```

// Using the facade
use Crumbls\Importer\Facades\Importer;

// Get default driver
$driver = Importer::driver();

// Get specific driver
$driver = Importer::driver('wordpress-xml');

// Register custom driver
Importer::extend('custom-driver', function ($app) {
    return new CustomDriver($app['config']['importer.drivers.custom-driver']);
});

// Or using dependency injection
public function import(ImportManager $importer)
{
    $driver = $importer->driver('wordpress-xml');
    // Use driver...
}