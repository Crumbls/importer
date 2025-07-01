<?php

declare(strict_types=1);

use Crumbls\Importer\Testing\MockDriver;

describe('Mock Driver', function () {
    
    it('can simulate successful imports', function () {
        $mock = mockDriver()
            ->withProcessedCount(100)
            ->withImportedCount(95)
            ->withFailedCount(5);
        
        $result = $mock->import('/fake/file.csv');
        
        expect($result)->toBeSuccessfulImport(processed: 100, imported: 95, failed: 5);
        expect($mock)->toHaveBeenCalledWith('Import', ['source' => '/fake/file.csv']);
    });
    
    it('can simulate failed imports', function () {
        $mock = mockDriver()
            ->shouldFail()
            ->withErrors(['Something went wrong']);
        
        $result = $mock->import('/fake/file.csv');
        
        expect($result)->toBeFailedImport(['/Something went wrong/']);
        expect($mock)->toHaveBeenCalledWith('Import');
    });
    
    it('tracks method calls', function () {
        $mock = mockDriver();
        
        $mock->validate('/fake/file.csv');
        $mock->preview('/fake/file.csv', 5);
        $mock->import('/fake/file.csv');
        
        expect($mock->wasValidationCalled())->toBeTrue();
        expect($mock->wasPreviewCalled())->toBeTrue();
        expect($mock->wasImportCalled())->toBeTrue();
        
        expect($mock->getImportCallCount())->toBe(1);
        expect($mock->getValidationCalls())->toHaveCount(1);
        expect($mock->getPreviewCalls())->toHaveCount(1);
    });
    
    it('can simulate preview data', function () {
        $mockData = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
            ['id' => 3, 'name' => 'Bob'],
        ];
        
        $mock = mockDriver()->withMockData($mockData);
        
        $preview = $mock->preview('/fake/file.csv', 2);
        
        expect($preview)->toHaveCount(2);
        expect($preview[0])->toBe(['id' => 1, 'name' => 'John']);
        expect($preview[1])->toBe(['id' => 2, 'name' => 'Jane']);
    });
    
    it('can be reset', function () {
        $mock = mockDriver()
            ->shouldFail()
            ->withProcessedCount(100);
        
        $mock->import('/fake/file.csv');
        
        expect($mock->wasImportCalled())->toBeTrue();
        
        $mock->reset();
        
        expect($mock->wasImportCalled())->toBeFalse();
        expect($mock->getImportCallCount())->toBe(0);
    });
    
    it('tracks temp storage usage', function () {
        $mock = mockDriver();
        
        $result = $mock->withTempStorage()->import('/fake/file.csv');
        
        expect($result->meta['temp_storage'])->toBeTrue();
    });
    
});

describe('Mock Driver with Manager', function () {
    
    it('can be used with importer manager', function () {
        $mock = mockDriver()
            ->withProcessedCount(50)
            ->withImportedCount(50);
        
        // This would require registering the mock driver with the manager
        // For now, just test direct usage
        $result = $mock->import('/fake/file.csv');
        
        expect($result)->toBeSuccessfulImport(processed: 50, imported: 50);
    });
    
});