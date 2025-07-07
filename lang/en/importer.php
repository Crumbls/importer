<?php

return [
    'command' => [
        'ready' => 'Importer command - ready for implementation',
        'process_finished' => 'Import process finished',
    ],
    
    'import' => [
        'created' => 'Import record created',
        'loaded' => 'Import record loaded',
        'processing_file' => 'Processing file: :file',
        'using_driver' => 'Using driver: :driver',
        'current_state' => 'Current state: :state',
        'continuing_with_driver' => 'Continuing with driver: :driver',
        'record_id' => 'Import record ID: :id',
    ],
    
    'state_machine' => [
        'transitioned_to' => 'Transitioned to :state state',
        'cannot_transition' => 'Cannot transition to :state state',
        'invalid_transition' => 'Invalid state transition attempted',
    ],
    
    'states' => [
        'analyzing' => 'analyzing',
        'processing' => 'processing', 
        'completed' => 'completed',
        'failed' => 'failed',
        'pending' => 'pending',
        'initializing' => 'initializing',
        'cancelled' => 'cancelled',
        'create_storage' => 'creating storage',
    ],
    
    'driver' => [
        'auto_detecting' => 'Auto-detecting compatible driver',
        'found_compatible' => 'Found compatible driver: :driver',
        'no_compatible' => 'No compatible driver found',
        'priority_check' => 'Checking driver priority: :priority',
    ],
    
    'storage' => [
        'creating_sqlite' => 'Creating SQLite database: :path',
        'sqlite_created' => 'SQLite database created successfully',
        'tables_created' => 'WordPress tables created',
        'connection_established' => 'Database connection established: :connection',
    ],
    
    'errors' => [
        'no_compatible_driver' => 'No compatible driver found for this import',
        'no_compatible_transition' => 'No compatible state transition available', 
        'input_not_provided' => 'No input file or import ID provided',
        'file_not_found' => 'File not found: :path',
        'invalid_file_format' => 'Invalid file format',
        'import_context_missing' => 'Import context not found in state machine',
        'database_connection_failed' => 'Failed to establish database connection',
    ],
];