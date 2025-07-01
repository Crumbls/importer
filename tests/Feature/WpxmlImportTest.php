<?php

declare(strict_types=1);

describe('WPXML Import', function () {
    
    it('can validate WordPress XML files', function () {
        $wpxmlFile = '/Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/WPXML.xml';
        
        if (!file_exists($wpxmlFile)) {
            $this->markTestSkipped('WPXML demo file not found');
        }
        
        $driver = importer()->driver('wpxml');
        
        expect($driver->validate($wpxmlFile))->toBeTrue('Should validate WordPress XML file');
    });
    
    it('rejects non-WordPress XML files', function () {
        $regularXml = createTempXmlFile('<root><item>test</item></root>');
        
        $driver = importer()->driver('wpxml');
        
        expect($driver->validate($regularXml))->toBeFalse('Should reject non-WordPress XML');
    });
    
    it('can preview WordPress XML content', function () {
        $wpxmlFile = '/Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/WPXML.xml';
        
        if (!file_exists($wpxmlFile)) {
            $this->markTestSkipped('WPXML demo file not found');
        }
        
        $driver = importer()->driver('wpxml');
        $preview = $driver->preview($wpxmlFile, 2);
        
        // Test new XML driver preview structure
        expect($preview)->toHaveKey('document_info');
        expect($preview)->toHaveKey('validation');
        expect($preview)->toHaveKey('entities');
        
        // Test document info
        expect($preview['document_info'])->toHaveKey('root_element');
        expect($preview['document_info']['root_element'])->toBe('rss');
        expect($preview['document_info'])->toHaveKey('namespaces');
        
        // Test validation results
        expect($preview['validation'])->toHaveKey('posts');
        expect($preview['validation']['posts']['found'])->toBeTrue();
        expect($preview['validation']['posts']['count'])->toBeGreaterThan(0);
        
        // Test entities preview
        expect($preview['entities'])->toHaveKey('posts');
        expect($preview['entities']['posts'])->toHaveKey('sample_records');
        expect($preview['entities']['posts']['sample_records'])->toBeArray();
        
        if (!empty($preview['entities']['posts']['sample_records'])) {
            $firstPost = $preview['entities']['posts']['sample_records'][0];
            expect($firstPost)->toHaveKey('title');
            expect($firstPost)->toHaveKey('content');
            expect($firstPost)->toHaveKey('link');
        }
    });
    
    it('supports fluent configuration', function () {
        $driver = importer()->driver('wpxml');
        
        expect($driver->extractPosts())->toBeInstanceOf(Crumbls\Importer\Drivers\WpxmlDriver::class);
        expect($driver->extractComments(false))->toBeInstanceOf(Crumbls\Importer\Drivers\WpxmlDriver::class);
        expect($driver->onlyPosts())->toBeInstanceOf(Crumbls\Importer\Drivers\WpxmlDriver::class);
        expect($driver->chunkSize(50))->toBeInstanceOf(Crumbls\Importer\Drivers\WpxmlDriver::class);
    });
    
    it('can import WordPress XML with multi-table storage', function () {
        $wpxmlFile = '/Users/chasemiller/PhpstormProjects/wordpress-bridge/storage/app/private/imports/WPXML.xml';
        
        if (!file_exists($wpxmlFile)) {
            $this->markTestSkipped('WPXML demo file not found');
        }
        
        // Clean up any previous pipeline state
        $pipelineStateDir = storage_path('pipeline');
        if (is_dir($pipelineStateDir)) {
            array_map('unlink', glob("$pipelineStateDir/*.json"));
        }
        
        $driver = importer()->driver('wpxml');
        $result = $driver->import($wpxmlFile);
        
        expect($result)->toBeInstanceOf(Crumbls\Importer\Contracts\ImportResult::class);
        
        // Debug the result if it fails
        if (!$result->success) {
            dump('Import failed with errors:', $result->errors);
            dump('Result meta:', $result->meta);
        }
        
        expect($result->success)->toBeTrue('Import should succeed');
        
        // Check that data was imported into different tables
        $storageReader = $driver->getStorageReader('posts');
        expect($storageReader)->not->toBeNull('Posts storage reader should be available');
        
        $postsCount = $storageReader->count();
        expect($postsCount)->toBeGreaterThan(0, 'Should have imported some posts');
        
        // Test comments table
        $commentsReader = $driver->getStorageReader('comments');
        expect($commentsReader)->not->toBeNull('Comments storage reader should be available');
        
        // Test users table
        $usersReader = $driver->getStorageReader('users');
        expect($usersReader)->not->toBeNull('Users storage reader should be available');
        
        // Test categories table
        $categoriesReader = $driver->getStorageReader('categories');
        expect($categoriesReader)->not->toBeNull('Categories storage reader should be available');
    });
    
});

// Helper function to create temporary XML file for testing
function createTempXmlFile(string $content): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'test_xml_');
    file_put_contents($tempFile, $content);
    
    // Schedule cleanup
    register_shutdown_function(function() use ($tempFile) {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    });
    
    return $tempFile;
}