<?php

return [
    'default' => env('IMPORTER_DRIVER', 'csv'),

    'drivers' => [
        'csv' => [
            'driver' => 'csv',
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ],
    ],

    'pipeline' => [
        'batch_size' => 1000,
        'memory_limit' => '256M',
        
        'state' => [
            'driver' => 'file',
            'path' => storage_path('pipeline'),
            'cleanup_after' => 3600, // seconds
        ],
    ],
];
