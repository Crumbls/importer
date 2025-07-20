<?php

return [

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
    ],
];