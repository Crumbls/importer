<?php

namespace Crumbls\Importer\Testing;

use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\RateLimit\RateLimiter;
use PHPUnit\Framework\Assert;

trait AssertionHelpers
{
    protected function assertImportResult(
        ImportResult $result,
        bool $expectedSuccess = true,
        ?int $expectedProcessed = null,
        ?int $expectedImported = null,
        ?int $expectedFailed = null,
        ?int $expectedErrorCount = null
    ): void {
        Assert::assertEquals($expectedSuccess, $result->success, 'Import success status should match');
        
        if ($expectedProcessed !== null) {
            Assert::assertEquals($expectedProcessed, $result->processed, 'Processed count should match');
        }
        
        if ($expectedImported !== null) {
            Assert::assertEquals($expectedImported, $result->imported, 'Imported count should match');
        }
        
        if ($expectedFailed !== null) {
            Assert::assertEquals($expectedFailed, $result->failed, 'Failed count should match');
        }
        
        if ($expectedErrorCount !== null) {
            Assert::assertCount($expectedErrorCount, $result->errors, 'Error count should match');
        }
    }
    
    protected function assertStorageData(
        StorageReader $reader,
        array $expectedData,
        bool $checkHeaders = true,
        bool $exactMatch = true
    ): void {
        $actualData = iterator_to_array($reader->all());
        
        if ($exactMatch) {
            Assert::assertEquals($expectedData, $actualData, 'Storage data should exactly match expected data');
        } else {
            Assert::assertCount(count($expectedData), $actualData, 'Storage should contain expected number of rows');
        }
        
        if ($checkHeaders && !empty($expectedData)) {
            $expectedHeaders = array_keys($expectedData[0]);
            Assert::assertEquals($expectedHeaders, $reader->getHeaders(), 'Storage headers should match');
        }
    }
    
    protected function assertPipelineStepCompleted(string $stateHash, string $stepName): void
    {
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        Assert::assertFileExists($statePath, 'Pipeline state file should exist');
        
        $state = json_decode(file_get_contents($statePath), true);
        $stepProgress = $state['step_progress'] ?? [];
        
        Assert::assertArrayHasKey($stepName, $stepProgress, "Step '{$stepName}' should be in progress");
        Assert::assertEquals('completed', $stepProgress[$stepName]['status'] ?? '', "Step '{$stepName}' should be completed");
    }
    
    protected function assertRateLimiterStats(
        ?array $stats,
        int $expectedMaxOperations,
        ?int $expectedCurrentCost = null,
        ?float $expectedUtilization = null
    ): void {
        Assert::assertNotNull($stats, 'Rate limiter stats should not be null');
        Assert::assertEquals($expectedMaxOperations, $stats['max_operations'], 'Max operations should match');
        
        if ($expectedCurrentCost !== null) {
            Assert::assertEquals($expectedCurrentCost, $stats['current_cost'], 'Current cost should match');
        }
        
        if ($expectedUtilization !== null) {
            Assert::assertEqualsWithDelta($expectedUtilization, $stats['utilization_percentage'], 1.0, 'Utilization should be within tolerance');
        }
    }
    
    protected function assertMemoryUsageReasonable(array $memoryStats, int $maxMemoryMB = 256): void
    {
        $maxMemoryBytes = $maxMemoryMB * 1024 * 1024;
        
        Assert::assertArrayHasKey('current', $memoryStats, 'Memory stats should include current usage');
        Assert::assertArrayHasKey('peak', $memoryStats, 'Memory stats should include peak usage');
        
        Assert::assertLessThanOrEqual($maxMemoryBytes, $memoryStats['current'], 'Current memory usage should be reasonable');
        Assert::assertLessThanOrEqual($maxMemoryBytes, $memoryStats['peak'], 'Peak memory usage should be reasonable');
    }
    
    protected function assertValidationErrors(array $errors, array $expectedPatterns): void
    {
        foreach ($expectedPatterns as $pattern) {
            $found = false;
            foreach ($errors as $error) {
                if (is_string($error) && preg_match($pattern, $error)) {
                    $found = true;
                    break;
                } elseif (is_array($error) && isset($error['message']) && preg_match($pattern, $error['message'])) {
                    $found = true;
                    break;
                }
            }
            Assert::assertTrue($found, "Expected error pattern '{$pattern}' not found in errors: " . json_encode($errors));
        }
    }
    
    protected function assertFileProcessingProgress(string $stateHash, float $minProgress = 0, float $maxProgress = 100): void
    {
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        $state = json_decode(file_get_contents($statePath), true);
        
        $progress = $state['processing_progress'] ?? 0;
        Assert::assertGreaterThanOrEqual($minProgress, $progress, 'Progress should be at least minimum');
        Assert::assertLessThanOrEqual($maxProgress, $progress, 'Progress should not exceed maximum');
    }
    
    protected function assertChunkProcessing(
        ImportResult $result,
        int $expectedChunks,
        int $chunkSize,
        int $tolerance = 1
    ): void {
        $actualChunks = ceil($result->processed / $chunkSize);
        Assert::assertEqualsWithDelta($expectedChunks, $actualChunks, $tolerance, 'Number of chunks processed should match expected');
    }
    
    protected function assertTemporaryStorageCleanup(string $stateHash): void
    {
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        
        if (file_exists($statePath)) {
            $state = json_decode(file_get_contents($statePath), true);
            $cleanupTime = $state['cleanup_scheduled_at'] ?? null;
            
            Assert::assertNotNull($cleanupTime, 'Cleanup should be scheduled');
            Assert::assertGreaterThan(time(), $cleanupTime, 'Cleanup should be scheduled for the future');
        }
    }
    
    protected function assertDriverCallHistory(
        MockDriver $mockDriver,
        array $expectedMethods,
        array $expectedCounts = []
    ): void {
        foreach ($expectedMethods as $method) {
            $methodName = "was{$method}Called";
            Assert::assertTrue($mockDriver->$methodName(), "Method '{$method}' should have been called");
            
            if (isset($expectedCounts[$method])) {
                $countMethodName = "get{$method}CallCount";
                if (method_exists($mockDriver, $countMethodName)) {
                    Assert::assertEquals($expectedCounts[$method], $mockDriver->$countMethodName(), "Method '{$method}' should have been called {$expectedCounts[$method]} times");
                }
            }
        }
    }
    
    protected function assertCsvStructure(string $filepath, array $expectedHeaders, int $expectedRows): void
    {
        Assert::assertFileExists($filepath, 'CSV file should exist');
        Assert::assertFileIsReadable($filepath, 'CSV file should be readable');
        
        $handle = fopen($filepath, 'r');
        $headers = fgetcsv($handle);
        
        Assert::assertEquals($expectedHeaders, $headers, 'CSV headers should match expected');
        
        $actualRows = 0;
        while (fgetcsv($handle) !== false) {
            $actualRows++;
        }
        
        fclose($handle);
        
        Assert::assertEquals($expectedRows, $actualRows, 'CSV should contain expected number of rows');
    }
}