# Crumbls Importer

> **NOTE: July 2025**
>
> This repository is being completely replaced and reworked.
>
> **Why?**
>
> I am redoing the extract and transform parts of the importer to make them more battle/production ready. 
> The previous implementation had limitations in reliability, scalability, and maintainability for real-world, 
> high-volume use cases. This rewrite is focused on robustness, better error handling, and overall production-readiness.
> 
> Right now, I have only reworked the extract portion of an ETL to make it fit our needs.  We will be reworking
> the remaining pieces before Laracon in Denver this year.
> 
> This is just scaffolding for the next version and will 100% be breaking.
>
> If you have any questions about this transition, please reach out via GitHub Issues.
> 
> Discord: https://discord.gg/7RZ5Esmn

## Current Status

- The codebase is undergoing a major rewrite focused on production-readiness and scalability.
- The extract (E) portion of ETL has been reworked with a flexible pipeline architecture.
- Multiple drivers are being reincorporated: CSV, XML, WPXML (WordPress XML), and database connection drivers.
- The system is designed for easy addition of new drivers—each driver implements a common contract and can be registered with the manager, supporting custom pipelines and configuration.
- Drivers encapsulate all logic for extracting and (optionally) transforming data from their source, and can be extended or swapped independently.
- The codebase aims for complete test coverage with both unit and feature tests, ensuring reliability for complex, high-volume imports.
- This project is not "vibe coded"—every feature is intentional, tested, and designed for real-world production use.
- The transform and load phases are not yet reworked and will change significantly soon.
- The API and structure are unstable and will change as development continues.
- Example and test scaffolding are present, but features are incomplete.
- Please see Discord for updates and discussion.

A Laravel package for importing data from remote sources with production-ready reliability, scalability, and Laravel-native architecture patterns.

## Installation

```bash
composer require crumbls/importer
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="Crumbls\Importer\ImporterServiceProvider" --tag="config"
```

## Architecture

### Laravel-Native Design

This package is built using Laravel's native patterns and components:

- **Database Operations**: Uses Laravel's Database Capsule Manager and Schema Builder instead of raw SQL
- **Configuration**: Leverages Laravel's config system with dot-notation access and environment awareness
- **Service Provider**: Standard Laravel service provider pattern for registration and bootstrapping
- **Manager Pattern**: Follows Laravel's Manager pattern for driver creation and management
- **Eloquent-Style Fluent API**: Method chaining similar to Laravel Query Builder
- **Validation**: Laravel-compatible validation rules and error handling
- **Facades**: Laravel facade pattern for convenient static access

### Core Components

- **ImporterManager**: Laravel Manager that creates driver instances (CSV, XML, WPXML, future drivers)
- **ImportPipeline**: Stateful pipeline that can pause/resume operations and handle large files
- **Drivers**: Individual import drivers with fluent APIs (CsvDriver, XmlDriver, WpxmlDriver)
- **StreamingParser**: Memory-efficient parsers for processing large files
- **Storage System**: Multiple backends (in-memory, SQLite) for temporary data storage
- **Configuration System**: Standardized, environment-aware configuration management
- **Migration Adapters**: Database migration tools with conflict resolution strategies

## Usage

### Basic Import

```php
use Crumbls\Importer\ImporterManager;

$importer = app(ImporterManager::class);

// Basic CSV import
$result = $importer->driver('csv')->import('/path/to/file.csv');

// WordPress XML import
$result = $importer->driver('wpxml')->import('/path/to/export.xml');
```

### Advanced Configuration

```php
// CSV import with full configuration
$result = $importer->driver('csv')
    ->delimiter(',')
    ->withHeaders()
    ->columns(['name', 'email', 'age'])
    ->required('email')
    ->numeric('age')
    ->chunkSize(1000)
    ->throttle(500) // max 500 rows per second
    ->withTempStorage()
    ->import('/path/to/large-file.csv');

// Column mapping and cleaning
$result = $importer->driver('csv')
    ->mapColumn('Full Name', 'name')
    ->mapColumn('Email Address', 'email')
    ->cleanColumnNames() // auto snake_case conversion
    ->import('/path/to/file.csv');
```

### WordPress Migration

```php
use Crumbls\Importer\Adapters\WordPressAdapter;

// Configure WordPress migration
$adapter = new WordPressAdapter([
    'connection' => [
        'host' => 'localhost',
        'database' => 'target_wp_db',
        'username' => 'user',
        'password' => 'pass'
    ],
    'conflict_resolution' => 'update',
    'dry_run' => false
], 'production');

// Import with migration
$result = $importer->driver('wpxml')
    ->withMigrationAdapter($adapter)
    ->import('/path/to/wordpress-export.xml');
```

### Error Handling

```php
try {
    $result = $importer->driver('csv')->import('/path/to/file.csv');
    
    if ($result->hasErrors()) {
        foreach ($result->getErrors() as $error) {
            Log::error('Import error', $error);
        }
    }
} catch (\Crumbls\Importer\Exceptions\ValidationException $e) {
    // Handle validation errors
} catch (\Crumbls\Importer\Exceptions\MemoryException $e) {
    // Handle memory limit issues
} catch (\Crumbls\Importer\Exceptions\ConnectionException $e) {
    // Handle database/network issues
}
```

## Key Features

### Memory Efficiency
- Streams large files without loading entire content into memory
- Chunked processing with configurable batch sizes
- Automatic memory monitoring and limit enforcement
- Temporary storage options (in-memory or SQLite)

### Resumable Operations
- Pipeline state management with automatic checkpointing
- Resume interrupted imports from last successful state
- File modification detection to prevent stale resumes

### Rate Limiting
- Configurable throttling for processing speed
- Per-second and per-minute rate limits
- Built-in rate limiter with statistics tracking

### Validation & Error Handling
- Column-level validation rules with Laravel-compatible syntax
- Comprehensive error categorization and recovery
- Detailed error reporting with context information
- Skip invalid rows option for fault tolerance

### Laravel Integration
- Native Laravel service provider registration
- Config system integration with environment support
- Database operations using Laravel's Schema Builder
- Fluent API design consistent with Laravel patterns

## Testing

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature

# Run with coverage
./vendor/bin/pest --coverage
```

## Development Commands

See `CLAUDE.md` for detailed development commands and architecture information.

## License

MIT