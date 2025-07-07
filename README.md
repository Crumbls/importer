# Crumbls Importer

A Laravel package for importing data from multiple source types with intelligent driver detection, state machine management, and production-ready reliability.

## Installation

```bash
composer require crumbls/importer
```

Publish the configuration and run migrations:

```bash
php artisan vendor:publish --provider="Crumbls\Importer\ImporterServiceProvider"
php artisan migrate
```

## Quick Start

### Command Line Interface

```bash
# Interactive mode - shows recent imports and options
php artisan importer

# Import a specific file (auto-detects driver)
php artisan importer /path/to/file.xml

# Force a specific driver
php artisan importer /path/to/file.csv --driver=csv

# Resume an existing import by ID
php artisan importer 123
```

### Programmatic Usage

```php
use Crumbls\Importer\Services\ImportService;

$importService = app(ImportService::class);

// Create a new import
$import = $importService->create([
    'driver' => 'auto',
    'source_type' => 'file',
    'source_detail' => '/path/to/file.xml',
    'metadata' => []
]);

// Start the import
$import->resume();
```

## Architecture

### Source Types & Drivers

The package supports multiple source types and uses intelligent driver detection:

**Source Types:**
- `file` - Local or remote files
- `connection` - Database connections 
- `api` - REST API endpoints
- `database` - Direct database queries

**Built-in Drivers:**
- `auto` - Intelligent auto-detection (default)
- `csv` - CSV files with delimiter detection
- `xml` - Generic XML files
- `wpxml` - WordPress XML exports
- `sql` - SQL dumps and queries

### Driver Management

Uses Laravel's Manager pattern with `extend()` support for custom drivers:

```php
use Crumbls\Importer\Services\ImportService;

// Register a custom driver
ImportService::extend('custom', function() {
    return new CustomDriver();
});

// Get available drivers
$drivers = app(ImportService::class)->getAvailableDrivers();
// ['auto', 'csv', 'xml', 'wpxml', 'sql', 'custom']

// Use specific driver
$driver = app(ImportService::class)->driver('xml');
```

### State Machine Management

Each import follows a state-driven workflow managed by individual drivers:

```php
// AutoDriver flow
'pending' → 'analyzing' → 'detected' → 'delegated'

// XmlDriver flow  
'pending' → 'parsing' → 'validating' → 'transforming' → 'importing' → 'completed'

// Resume from any state
$import->resume(); // Continues from current state
```

## Source Type Examples

### File Imports

```php
// Auto-detected file import
$import = $importService->create([
    'driver' => 'auto',
    'source_type' => 'file',
    'source_detail' => '/path/to/data.xml'
]);

// Force specific driver
$import = $importService->create([
    'driver' => 'csv',
    'source_type' => 'file', 
    'source_detail' => '/path/to/data.csv'
]);
```

### Database Connections (Future)

```php
$import = $importService->create([
    'driver' => 'mysql',
    'source_type' => 'connection',
    'source_detail' => 'mysql://user:pass@host:port/database'
]);
```

### API Endpoints (Future)

```php
$import = $importService->create([
    'driver' => 'api',
    'source_type' => 'api',
    'source_detail' => 'https://api.example.com/data'
]);
```

### Direct Database Queries (Future)

```php
$import = $importService->create([
    'driver' => 'sql',
    'source_type' => 'database',
    'source_detail' => 'SELECT * FROM users WHERE active = 1'
]);
```

## Intelligent Auto-Detection

The AutoDriver analyzes sources and delegates to specialized drivers:

### XML Detection
- Detects WordPress XML vs generic XML
- Analyzes encoding, namespaces, root elements
- Stores metadata for specialized processing

### CSV Detection  
- Auto-detects delimiters (`,`, `;`, `\t`, `|`, `:`)
- Identifies headers vs data rows
- Analyzes file structure and encoding

```php
// AutoDriver metadata example
[
    'detected_driver' => 'wpxml',
    'file_analysis' => [
        'file_size' => 1048576,
        'xml_analysis' => [
            'encoding' => 'UTF-8',
            'root_element' => 'rss',
            'namespace_detected' => true
        ]
    ]
]
```

## Driver Compatibility

Drivers implement fast compatibility checking:

```php
interface DriverContract 
{
    public function canHandle(Import $import): bool;
    // ... other methods
}

// Example implementation
public function canHandle(Import $import): bool 
{
    if ($import->source_type === 'file') {
        $extension = pathinfo($import->source_detail, PATHINFO_EXTENSION);
        return strtolower($extension) === 'xml';
    }
    return false;
}
```

## Queue Support (Upcoming)

Designed for Laravel queue integration:

```php
// Future queue support
$import = $importService->create([
    'driver' => 'auto',
    'source_type' => 'file',
    'source_detail' => '/large-file.csv',
    'queue' => 'imports',
    'metadata' => ['timeout' => 3600]
]);
```

## Configuration

Configure default drivers and settings:

```php
// config/importer.php
return [
    'default_driver' => env('IMPORTER_DEFAULT_DRIVER', 'auto'),
    'drivers' => [
        // Driver-specific configurations
    ],
];
```

## Testing

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites  
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature
```

## Current Status

**✅ Completed:**
- Multi-source architecture (file, connection, api, database)
- Intelligent auto-detection with AutoDriver
- State machine management per driver
- Laravel Manager pattern with extend() support
- Command line interface with interactive mode
- Fast driver compatibility checking

**🚧 In Progress:**
- Queue integration
- Step classes for testability
- Additional driver implementations

**📋 Planned:**
- Database connection drivers
- API endpoint drivers
- Advanced error handling and recovery
- Comprehensive test coverage

## Development

See `CLAUDE.md` for detailed development commands and architecture information.

## License

MIT