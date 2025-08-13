# Crumbls Importer

A Laravel 12+ and Filament 4+ package for importing data with state machine-driven workflows.


## Status

**Early Beta** - This package is under active development and not anywhere near ready for production use.

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
php artisan importer:install

php artisan importer
```

## Architecture

This package uses a state machine approach where each import progresses through states:

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

---

### Compatibility Notes
- **Unix-like Terminals (macOS, Linux, WSL, SSH):**
  - Works in most modern terminal emulators (iTerm2, GNOME Terminal, macOS Terminal, Alacritty, etc.)
  - Uses ANSI escape codes and the alternate screen buffer for a clean experience
- **Windows:**
  - Modern Windows Terminal and WSL are supported
  - Legacy cmd.exe and older PowerShell versions may not support ANSI escapes or alternate buffer, and TUI may not render correctly
  - Recommend using Windows Terminal or WSL for best results
- **tmux/screen:**
  - Supported if alternate buffer is enabled (default)

