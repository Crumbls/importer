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

A Laravel package for importing data from remote sources.

## Installation

```bash
composer require crumbls/importer
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="Crumbls\Importer\ImporterServiceProvider" --tag="config"
```

## Usage

```php
use Crumbls\Importer\ImporterManager;

$importer = app(ImporterManager::class);

// Basic CSV import
$result = $importer->driver('csv')->import('/path/to/file.csv');

// Import with temporary storage for large files
$result = $importer->driver('csv')
    ->import('/path/to/file.csv');

// Preview data
$preview = $importer->driver('csv')->preview('/path/to/file.csv', 5);
```

## Memory Efficiency

Designed for handling massive CSV files:
- Pipeline can be stopped/resumed at any point
- Streaming file reading to avoid memory limits
- Chunked database operations for large datasets
- Temporary storage for processing huge files

## Pipeline Pattern

Each driver uses a consistent pipeline pattern that can be extended with custom steps. The pipeline maintains context between steps and can be configured to handle different processing requirements.

## License

MIT
