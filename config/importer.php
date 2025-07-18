<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Import Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default import driver that will be used when
    | no specific driver is specified.
    |
    */
    'default' => 'csv',

    /*
    |--------------------------------------------------------------------------
    | Import Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the import drivers for your application.
    |
    */
    'drivers' => [
        'csv' => [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ],
        'excel' => [
            'worksheet' => 0,
            'header_row' => 1,
        ],
        'json' => [
            'root_key' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Settings
    |--------------------------------------------------------------------------
    |
    | Configure various import settings such as chunk size, memory limits, etc.
    |
    */
    'chunk_size' => 1000,
    'memory_limit' => '512M',
    'timeout' => 300,

    /*
    |--------------------------------------------------------------------------
    | Storage Settings
    |--------------------------------------------------------------------------
    |
    | Configure storage settings for import files and logs.
    |
    */
    'storage' => [
        'disk' => 'local',
        'path' => 'imports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Configure queue settings for background import processing.
    |
    */
    'queue' => [
        'enabled' => true,
        'connection' => 'default',
        'queue' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Configure the models used by the importer package.
    |
    */
    'models' => [
        'import' => \Crumbls\Importer\Models\Import::class,
        'importmodelmap' => \Crumbls\Importer\Models\ImportModelMap::class,
        
        // WordPress-specific overrides (only if different from convention)
        'attachment' => 'media', // WordPress attachment maps to Media model
        'nav_menu_item' => 'menu_item', // WordPress nav_menu_item maps to MenuItem model
        
        // Other specific mappings can be added here as needed
        // Most models will be auto-resolved using app namespace + studly case
    ],
];