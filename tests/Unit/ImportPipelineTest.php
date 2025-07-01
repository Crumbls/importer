<?php

use Crumbls\Importer\Pipeline\ImportPipeline;
use Crumbls\Importer\Pipeline\PipelineContext;

beforeEach(function () {
    // Clean up any existing state files before each test
    $stateDir = storage_path('pipeline');
    if (is_dir($stateDir)) {
        array_map('unlink', glob("$stateDir/*.json"));
    }
    
    // Create test CSV file
    $this->testCsvPath = __DIR__ . '/test.csv';
    file_put_contents($this->testCsvPath, "name,email,age\nJohn,john@test.com,25\nJane,jane@test.com,30");
    
    $this->pipeline = new ImportPipeline();
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

it('generates consistent state hash for same inputs', function () {
    $options = ['delimiter' => ','];
    
    $hash1 = $this->pipeline->process($this->testCsvPath, $options);
    $hash2 = $this->pipeline->process($this->testCsvPath, $options);
    
    expect($hash1->meta['state_hash'])->toBe($hash2->meta['state_hash']);
});

it('generates different state hash for different options', function () {
    $result1 = $this->pipeline->process($this->testCsvPath, ['delimiter' => ',']);
    $result2 = $this->pipeline->process($this->testCsvPath, ['delimiter' => ';']);
    
    expect($result1->meta['state_hash'])->not->toBe($result2->meta['state_hash']);
});

it('creates state file on first run', function () {
    $result = $this->pipeline->process($this->testCsvPath, []);
    
    $stateDir = storage_path('pipeline');
    $stateFile = $stateDir . '/' . $result->meta['state_hash'] . '.json';
    
    expect(file_exists($stateFile))->toBeTrue();
    
    $state = json_decode(file_get_contents($stateFile), true);
    expect($state['source'])->toBe($this->testCsvPath);
    expect($state['status'])->toBe('completed');
});

it('resumes from existing state on second run', function () {
    $options = ['delimiter' => ','];
    
    // First run
    $result1 = $this->pipeline->process($this->testCsvPath, $options);
    expect($result1->meta['resumed'])->toBeFalse();
    
    // Second run with same parameters
    $pipeline2 = new ImportPipeline();
    $result2 = $pipeline2->process($this->testCsvPath, $options);
    expect($result2->meta['resumed'])->toBeTrue();
});

it('does not resume if file has been modified', function () {
    $options = ['delimiter' => ','];
    
    // First run
    $result1 = $this->pipeline->process($this->testCsvPath, $options);
    expect($result1->meta['resumed'])->toBeFalse();
    
    // Modify the file
    sleep(1); // Ensure different mtime
    file_put_contents($this->testCsvPath, "name,email\nJohn,john@test.com");
    
    // Second run should start fresh
    $pipeline2 = new ImportPipeline();
    $result2 = $pipeline2->process($this->testCsvPath, $options);
    expect($result2->meta['resumed'])->toBeFalse();
});

it('does not resume if file does not exist', function () {
    $options = ['delimiter' => ','];
    
    // First run
    $result1 = $this->pipeline->process($this->testCsvPath, $options);
    
    // Delete the file
    unlink($this->testCsvPath);
    
    // Create new file with same name
    file_put_contents($this->testCsvPath, "different,content\ntest,data");
    
    // Second run should start fresh
    $pipeline2 = new ImportPipeline();
    $result2 = $pipeline2->process($this->testCsvPath, $options);
    expect($result2->meta['resumed'])->toBeFalse();
});

it('includes temp storage in state hash when specified', function () {
    $pipeline1 = new ImportPipeline();
    $pipeline2 = new ImportPipeline();
    
    $result1 = $pipeline1->process($this->testCsvPath, []);
    $result2 = $pipeline2->withTempStorage()->process($this->testCsvPath, []);
    
    expect($result1->meta['state_hash'])->not->toBe($result2->meta['state_hash']);
});

it('preserves state data through completion', function () {
    $options = ['delimiter' => ',', 'custom_option' => 'test'];
    
    $result = $this->pipeline->process($this->testCsvPath, $options);
    
    $stateFile = storage_path('pipeline') . '/' . $result->meta['state_hash'] . '.json';
    $state = json_decode(file_get_contents($stateFile), true);
    
    expect($state)->toHaveKeys(['source', 'options', 'status', 'started_at', 'completed_at']);
    expect($state['options'])->toBe($options);
    expect($state['status'])->toBe('completed');
});

it('returns successful import result', function () {
    $result = $this->pipeline->process($this->testCsvPath, []);
    
    expect($result->success)->toBeTrue();
    expect($result->processed)->toBe(0); // No actual processing yet
    expect($result->imported)->toBe(0);
    expect($result->failed)->toBe(0);
    expect($result->errors)->toBeEmpty();
    expect($result->meta)->toHaveKeys(['source', 'state_hash', 'resumed']);
});
