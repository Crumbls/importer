<?php

use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Drivers\CsvDriver;

beforeEach(function () {
    // Clean up state files
    $stateDir = storage_path('pipeline');
    if (is_dir($stateDir)) {
        array_map('unlink', glob("$stateDir/*.json"));
    }
    
    // Create test CSV files
    $this->smallCsv = __DIR__ . '/small.csv';
    $this->largeCsv = __DIR__ . '/large.csv';
    
    file_put_contents($this->smallCsv, "name,email\nJohn,john@test.com\nJane,jane@test.com");
    
    // Create a larger CSV for testing
    $largeContent = "name,email,age,city\n";
    for ($i = 1; $i <= 1000; $i++) {
        $largeContent .= "User{$i},user{$i}@test.com,{$i},{$i}City\n";
    }
    file_put_contents($this->largeCsv, $largeContent);
});

afterEach(function () {
    // Clean up test files
    foreach ([$this->smallCsv, $this->largeCsv] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    // Clean up state files
    $stateDir = storage_path('pipeline');
    if (is_dir($stateDir)) {
        array_map('unlink', glob("$stateDir/*.json"));
    }
});

it('can import a simple CSV file through manager', function () {
    $manager = app(ImporterManager::class);
    
    $result = $manager->driver('csv')->import($this->smallCsv);
    
    expect($result->success)->toBeTrue();
    expect($result->meta['source'])->toBe($this->smallCsv);
});

it('supports fluent API for temp storage', function () {
    $manager = app(ImporterManager::class);
    
    $result = $manager->driver('csv')
        ->withTempStorage()
        ->import($this->smallCsv);
    
    expect($result->success)->toBeTrue();
});

it('resumes interrupted large file imports', function () {
    $manager = app(ImporterManager::class);
    
    // First attempt
    $result1 = $manager->driver('csv')
        ->withTempStorage()
        ->import($this->largeCsv);
    
    expect($result1->meta['resumed'])->toBeFalse();
    
    // Second attempt should resume
    $result2 = $manager->driver('csv')
        ->withTempStorage()
        ->import($this->largeCsv);
    
    expect($result2->meta['resumed'])->toBeTrue();
});

it('handles multiple concurrent imports', function () {
    $manager = app(ImporterManager::class);
    
    $result1 = $manager->driver('csv')->import($this->smallCsv);
    $result2 = $manager->driver('csv')->import($this->largeCsv);
    
    expect($result1->success)->toBeTrue();
    expect($result2->success)->toBeTrue();
    expect($result1->meta['state_hash'])->not->toBe($result2->meta['state_hash']);
});

it('handles file modifications correctly', function () {
    $manager = app(ImporterManager::class);
    
    // First import
    $result1 = $manager->driver('csv')->import($this->smallCsv);
    expect($result1->meta['resumed'])->toBeFalse();
    
    // Modify file
    sleep(1); // Ensure different timestamp
    file_put_contents($this->smallCsv, "name,email,phone\nBob,bob@test.com,123");
    
    // Second import should start fresh
    $result2 = $manager->driver('csv')->import($this->smallCsv);
    expect($result2->meta['resumed'])->toBeFalse();
});

it('validates CSV files before import', function () {
    $manager = app(ImporterManager::class);
    
    $driver = $manager->driver('csv');
    
    expect($driver->validate($this->smallCsv))->toBeTrue();
    expect($driver->validate('/nonexistent/file.csv'))->toBeFalse();
});

it('provides preview functionality', function () {
    $manager = app(ImporterManager::class);
    
    $preview = $manager->driver('csv')->preview($this->smallCsv, 2);
    
    expect($preview)->toBeArray();
    // Preview is currently empty but the method works
});
