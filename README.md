# Crumbls Importer

A Laravel 12+ and Filament 4+ package for importing data with state machine-driven workflows.

## Status

**Early Beta** - This package is under active development and not yet ready for production use.

## Features

- **State Machine Architecture**: Import workflows managed through robust state transitions
- **Driver System**: Extensible drivers for different data sources (WordPress XML, CSV, XML, Auto-detection)
- **Filament Integration**: Beautiful admin interface with Filament 4.x components
- **Memory Efficient**: Streaming processing for large files
- **Data Analysis**: Intelligent field type detection and mapping recommendations
- **Storage Abstraction**: Pluggable storage drivers with SQLite support
- **Queue Support**: Background processing for large imports

## Requirements

- PHP 8.2+
- Laravel 12.0+
- Filament 4.x

## Installation

```bash
composer require crumbls/importer
```

## Basic Usage

1. **Install the plugin** in your Filament panel:

```php
// In your PanelProvider
use Crumbls\Importer\ImporterPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            ImporterPlugin::make(),
        ]);
}
```

2. **Run migrations**:

```bash
php artisan migrate
```

3. **Use the interface**: Navigate to the Imports section in your Filament admin panel to create and manage imports through the user-friendly interface.

## Architecture

This package uses a state machine approach where each import progresses through states:

1. **Initializing** - Setting up the import
2. **Analyzing** - Examining the source data
3. **Processing** - Importing the data
4. **Completed** - Import finished successfully

Each state handles its own UI presentation and business logic, providing a clean separation of concerns.

## Development Philosophy

All code in this package is **human-written with intent**. We believe in:

- Clear, readable code over clever abstractions
- Explicit error handling and meaningful exceptions
- Memory-efficient processing for real-world data sizes
- Comprehensive testing and documentation
- Security-first design principles

## Testing

```bash
composer test
```

## Contributing

This package is in early beta. We welcome feedback and contributions, but please note that APIs may change significantly before the stable release.

## License

MIT License. See LICENSE file for details.

## Support

For issues and questions, please use the GitHub issue tracker.