<?php

use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Testing\ImporterTestHelpers;
use Crumbls\Importer\Testing\TestFixtures;
use Crumbls\Importer\Testing\MockDriver;
use Crumbls\Importer\Testing\AssertionHelpers;
use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Storage\StorageReader;

// Global setup and cleanup
beforeEach(function () {
    $this->importerManager = app(ImporterManager::class);
});

afterEach(function () {
    \Crumbls\Importer\Testing\ImporterTestHelpers::cleanupTempDir();
});

// Helper functions for Pest tests
function importer(): ImporterManager
{
    return app(ImporterManager::class);
}

function csvDriver(): \Crumbls\Importer\Drivers\CsvDriver
{
    return importer()->driver('csv');
}

function createTestCsv(array $data, array $headers = null): string
{
    return \Crumbls\Importer\Testing\ImporterTestHelpers::createTempCsvFile($data, $headers);
}

function createLargeCsv(int $rows, array $headers = null): string
{
    return \Crumbls\Importer\Testing\ImporterTestHelpers::createLargeCsvFile($rows, $headers);
}

function createInvalidCsv(): string
{
    return \Crumbls\Importer\Testing\ImporterTestHelpers::createInvalidCsvFile();
}

function basicCsvData(): array
{
    return TestFixtures::basicCsvData();
}

function csvWithHeaders(): array
{
    return TestFixtures::csvWithHeaders();
}

function csvWithValidationIssues(): array
{
    return TestFixtures::csvWithValidationIssues();
}

function mockDriver(): MockDriver
{
    return new MockDriver();
}

// Custom Pest expectations
expect()->extend('toBeSuccessfulImport', function (int $processed = null, int $imported = null, int $failed = null) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->success)->toBeTrue('Import should be successful');
    expect($result->errors)->toBeEmpty('Import should have no errors');
    
    if ($processed !== null) {
        expect($result->processed)->toBe($processed, "Expected {$processed} rows to be processed");
    }
    
    if ($imported !== null) {
        expect($result->imported)->toBe($imported, "Expected {$imported} rows to be imported");
    }
    
    if ($failed !== null) {
        expect($result->failed)->toBe($failed, "Expected {$failed} rows to fail");
    }
    
    return $this;
});

expect()->extend('toBeFailedImport', function (array $expectedErrorPatterns = []) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->success)->toBeFalse('Import should have failed');
    expect($result->errors)->not->toBeEmpty('Failed import should have errors');
    
    foreach ($expectedErrorPatterns as $pattern) {
        $found = false;
        foreach ($result->errors as $error) {
            if (preg_match($pattern, is_array($error) ? $error['message'] : $error)) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue("Expected error pattern '{$pattern}' not found");
    }
    
    return $this;
});

expect()->extend('toHaveProcessedRows', function (int $count) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->processed)->toBe($count, "Expected {$count} rows to be processed");
    
    return $this;
});

expect()->extend('toHaveImportedRows', function (int $count) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->imported)->toBe($count, "Expected {$count} rows to be imported");
    
    return $this;
});

expect()->extend('toHaveFailedRows', function (int $count) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->failed)->toBe($count, "Expected {$count} rows to fail");
    
    return $this;
});

expect()->extend('toHaveErrors', function (int $count) {
    /** @var ImportResult $result */
    $result = $this->value;
    
    expect($result->errors)->toHaveCount($count, "Expected {$count} errors");
    
    return $this;
});

expect()->extend('toContainStorageData', function (array $expectedData) {
    /** @var StorageReader $reader */
    $reader = $this->value;
    
    $actualData = iterator_to_array($reader->all());
    expect($actualData)->toBe($expectedData, 'Storage should contain expected data');
    
    return $this;
});

expect()->extend('toHaveStorageHeaders', function (array $expectedHeaders) {
    /** @var StorageReader $reader */
    $reader = $this->value;
    
    expect($reader->getHeaders())->toBe($expectedHeaders, 'Storage should have expected headers');
    
    return $this;
});

expect()->extend('toHaveStorageCount', function (int $expectedCount) {
    /** @var StorageReader $reader */
    $reader = $this->value;
    
    expect($reader->count())->toBe($expectedCount, "Storage should contain {$expectedCount} rows");
    
    return $this;
});

expect()->extend('toHaveCompletedStep', function (string $stepName) {
    /** @var string $stateHash */
    $stateHash = $this->value;
    
    $statePath = storage_path('pipeline/' . $stateHash . '.json');
    expect(file_exists($statePath))->toBeTrue('Pipeline state file should exist');
    
    $state = json_decode(file_get_contents($statePath), true);
    $stepProgress = $state['step_progress'] ?? [];
    
    expect(array_key_exists($stepName, $stepProgress))->toBeTrue("Step '{$stepName}' should be in progress");
    expect($stepProgress[$stepName]['status'] ?? '')->toBe('completed', "Step '{$stepName}' should be completed");
    
    return $this;
});

expect()->extend('toHaveRateLimitStats', function (int $maxOperations, int $currentCost = null, float $utilization = null) {
    /** @var array|null $stats */
    $stats = $this->value;
    
    expect($stats)->not->toBeNull('Rate limiter stats should not be null');
    expect($stats['max_operations'])->toBe($maxOperations, 'Max operations should match');
    
    if ($currentCost !== null) {
        expect($stats['current_cost'])->toBe($currentCost, 'Current cost should match');
    }
    
    if ($utilization !== null) {
        expect($stats['utilization_percentage'])->toBeGreaterThanOrEqual($utilization - 1.0)
            ->toBeLessThanOrEqual($utilization + 1.0);
    }
    
    return $this;
});

expect()->extend('toHaveBeenCalledWith', function (string $method, array $expectedArgs = []) {
    /** @var MockDriver $mockDriver */
    $mockDriver = $this->value;
    
    $methodName = "was{$method}Called";
    expect($mockDriver->$methodName())->toBeTrue("Method '{$method}' should have been called");
    
    if (!empty($expectedArgs)) {
        $getCallsMethod = "get{$method}Calls";
        if (method_exists($mockDriver, $getCallsMethod)) {
            $calls = $mockDriver->$getCallsMethod();
            expect($calls)->not->toBeEmpty("Method '{$method}' should have call history");
            
            $lastCall = end($calls);
            foreach ($expectedArgs as $key => $expectedValue) {
                expect($lastCall[$key] ?? null)->toBe($expectedValue, "Argument '{$key}' should match expected value");
            }
        }
    }
    
    return $this;
});

// Custom datasets for Pest
dataset('csv_delimiters', [
    'comma' => [',', "id,name,email\n1,John,john@example.com\n2,Jane,jane@example.com"],
    'semicolon' => [';', "id;name;email\n1;John;john@example.com\n2;Jane;jane@example.com"],
    'tab' => ["\t", "id\tname\temail\n1\tJohn\tjohn@example.com\n2\tJane\tjane@example.com"],
    'pipe' => ['|', "id|name|email\n1|John|john@example.com\n2|Jane|jane@example.com"],
]);

dataset('validation_rules', [
    'basic' => TestFixtures::csvValidationRules()['basic'],
    'strict' => TestFixtures::csvValidationRules()['strict'],
    'lenient' => TestFixtures::csvValidationRules()['lenient'],
]);

dataset('large_file_sizes', [
    'small' => 100,
    'medium' => 1000,
    'large' => 10000,
]);

dataset('rate_limits', [
    'conservative' => [100, 10], // rows/sec, chunks/min
    'moderate' => [500, 30],
    'aggressive' => [1000, 60],
]);

// Architecture helpers
function assertArchitecture(): \Pest\Arch\Contracts\Architeture
{
    return arch();
}

// Cleanup function
function cleanupImporterTests(): void
{
    \Crumbls\Importer\Testing\ImporterTestHelpers::cleanupTempDir();
}