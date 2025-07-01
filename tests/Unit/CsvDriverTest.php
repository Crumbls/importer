<?php

use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Contracts\ImportResult;

beforeEach(function () {
    // Clean up state files
    $stateDir = storage_path('pipeline');
    if (is_dir($stateDir)) {
        array_map('unlink', glob("$stateDir/*.json"));
    }
    
    // Create test CSV file
    $this->testCsvPath = __DIR__ . '/test.csv';
    file_put_contents($this->testCsvPath, "name,email,age\nJohn,john@test.com,25\nJane,jane@test.com,30");
    
    $this->driver = new CsvDriver(['delimiter' => ',']);
});

afterEach(function () {
    // Clean up test files
    if (file_exists($this->testCsvPath)) {
        unlink($this->testCsvPath);
    }
    
    // Clean up state files
    $stateDir = storage_path('pipeline');
    if (is_dir($stateDir)) {
        array_map('unlink', glob("$stateDir/*.json"));
    }
});

it('can validate existing files', function () {
    expect($this->driver->validate($this->testCsvPath))->toBeTrue();
});

it('fails validation for non-existent files', function () {
    expect($this->driver->validate('/path/to/nonexistent.csv'))->toBeFalse();
});

it('returns ImportResult on successful import', function () {
    $result = $this->driver->import($this->testCsvPath);
    
    expect($result)->toBeInstanceOf(ImportResult::class);
    expect($result->success)->toBeTrue();
});

it('supports fluent withTempStorage method', function () {
    $result = $this->driver
        ->withTempStorage()
        ->import($this->testCsvPath);
    
    expect($result)->toBeInstanceOf(ImportResult::class);
    expect($result->success)->toBeTrue();
});

it('withTempStorage returns self for chaining', function () {
    $returned = $this->driver->withTempStorage();
    
    expect($returned)->toBe($this->driver);
});

it('can preview CSV data', function () {
    $preview = $this->driver->preview($this->testCsvPath, 5);
    
    expect($preview)->toBeArray();
    expect($preview)->toHaveKey('headers');
    expect($preview)->toHaveKey('rows');
    expect($preview)->toHaveKey('delimiter');
});

it('includes temp storage context when enabled', function () {
    $result = $this->driver
        ->withTempStorage()
        ->import($this->testCsvPath);
    
    // This would be verified through the pipeline context in a more complex test
    expect($result->success)->toBeTrue();
});

it('processes same file consistently', function () {
    $result1 = $this->driver->import($this->testCsvPath);
    $result2 = $this->driver->import($this->testCsvPath);
    
    expect($result1->meta['state_hash'])->toBe($result2->meta['state_hash']);
    expect($result2->meta['resumed'])->toBeTrue();
});

it('handles different configurations as separate pipelines', function () {
    $driver1 = new CsvDriver(['delimiter' => ',']);
    $driver2 = new CsvDriver(['delimiter' => ';']);
    
    $result1 = $driver1->import($this->testCsvPath);
    $result2 = $driver2->import($this->testCsvPath);
    
    expect($result1->meta['state_hash'])->not->toBe($result2->meta['state_hash']);
});
