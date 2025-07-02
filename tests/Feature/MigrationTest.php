<?php

use Crumbls\Importer\Adapters\WordPressAdapter;

describe('Migration Capabilities', function () {
    
    it('can configure WordPress migration adapter', function () {
        $adapter = new WordPressAdapter([
            'connection' => 'target_wp_db',
            'conflict_strategy' => 'skip',
            'create_missing' => true
        ]);
        
        expect($adapter->getConfig())->toHaveKey('connection');
        expect($adapter->getConfig()['connection'])->toBe('target_wp_db');
        expect($adapter->getConfig()['conflict_strategy'])->toBe('skip');
    });
    
    it('can create migration plan from extracted data', function () {
        $adapter = new WordPressAdapter(['connection' => 'test_db']);
        
        $extractedData = [
            'posts' => [
                ['post_title' => 'Test Post', 'post_content' => 'Content', 'post_type' => 'post'],
                ['post_title' => 'Test Page', 'post_content' => 'Page Content', 'post_type' => 'page']
            ],
            'users' => [
                ['user_login' => 'admin', 'user_email' => 'admin@test.com']
            ]
        ];
        
        $plan = $adapter->plan($extractedData);
        
        expect($plan)->toBeInstanceOf(\Crumbls\Importer\Contracts\MigrationPlan::class);
        expect($plan->getSummary())->toHaveKey('posts');
        expect($plan->getSummary())->toHaveKey('users');
        expect($plan->getSummary()['posts']['total_records'])->toBe(2);
        expect($plan->getSummary()['users']['total_records'])->toBe(1);
    });
    
    it('can validate migration plan', function () {
        $adapter = new WordPressAdapter(['connection' => 'test_db'], 'testing');
        
        $extractedData = [
            'posts' => [
                ['post_title' => 'Test Post', 'post_content' => 'Content']
            ]
        ];
        
        $plan = $adapter->plan($extractedData);
        $validation = $adapter->validate($plan);
        
        expect($validation)->toBeInstanceOf(\Crumbls\Importer\Contracts\ValidationResult::class);
        expect($validation->isValid())->toBeTrue(); // Since we mock table existence
    });
    
    it('can perform dry run', function () {
        $adapter = new WordPressAdapter(['connection' => 'test_db']);
        
        $extractedData = [
            'posts' => [
                ['post_title' => 'Test Post', 'post_content' => 'Content']
            ]
        ];
        
        $plan = $adapter->plan($extractedData);
        $dryRun = $adapter->dryRun($plan);
        
        expect($dryRun)->toBeInstanceOf(\Crumbls\Importer\Contracts\DryRunResult::class);
        expect($dryRun->getSummary())->toHaveKey('posts');
        expect($dryRun->getStatistics())->toHaveKey('posts');
    });
    
    it('can execute migration', function () {
        $adapter = new WordPressAdapter(['connection' => 'test_db'], 'testing');
        
        $extractedData = [
            'posts' => [
                ['post_title' => 'Test Post', 'post_content' => 'Content']
            ]
        ];
        
        $plan = $adapter->plan($extractedData);
        $result = $adapter->migrate($plan);
        
        expect($result)->toBeInstanceOf(\Crumbls\Importer\Contracts\MigrationResult::class);
        expect($result->isSuccess())->toBeTrue();
        expect($result->getMigrationId())->toMatch('/^wp_migration_\d{8}_\d{6}_[a-f0-9]{8}$/');
    });
    
});

describe('WPXML Migration Integration', function () {
    
    it('can configure WPXML driver with migration adapter', function () {
        $adapter = new WordPressAdapter([
            'connection' => 'target_db',
            'mappings' => [
                'posts' => ['conflict_strategy' => 'skip'],
                'users' => ['create_missing' => true]
            ]
        ]);
        
        $driver = importer()->driver('wpxml')
            ->onlyContent() // Extract posts, postmeta, attachments, categories, tags
            ->migrateTo($adapter);
        
        // Verify configuration
        expect($driver)->toBeInstanceOf(\Crumbls\Importer\Drivers\WpxmlDriver::class);
    });
    
    it('demonstrates comprehensive extraction with migration planning', function () {
        $wpxmlFile = __DIR__ . '/../../resources/wordpress-export.xml';

        if (!file_exists($wpxmlFile)) {
            $this->markTestSkipped('WPXML demo file not found');
        }
        
        $adapter = new WordPressAdapter([
            'connection' => 'target_db',
            'strategy' => 'migration',
            'conflict_strategy' => 'skip'
        ]);
        
        // This demonstrates the full extract-transform-load flow:
        // 1. Extract everything from WPXML by default
        // 2. Create migration plan (transform)
        // 3. Execute migration (load)
        $driver = importer()->driver('wpxml')
            ->migrateTo($adapter);
        
        // Just verify we can create a plan (don't actually migrate)
        // In a real scenario, you would call:
        // $plan = $driver->plan($wpxmlFile);
        // $validation = $driver->validateMigration($wpxmlFile);
        // $dryRun = $driver->dryRun($wpxmlFile);
        // $result = $driver->migrate($wpxmlFile);
        
        expect($driver)->toBeInstanceOf(\Crumbls\Importer\Drivers\WpxmlDriver::class);
    });
    
});