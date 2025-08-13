<?php

namespace Crumbls\Importer;

use Filament\Contracts\Plugin;
use Filament\Panel;

class ImporterPlugin implements Plugin
{
    public function getId(): string
    {
        return 'importer';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
//                \Crumbls\Importer\Filament\Resources\ImportResource::class,
            ])
            ->pages([
                // Register Filament pages here
            ]);
    }

    public function boot(Panel $panel): void
    {
        // Boot logic here
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }
}
