<?php

namespace Crumbls\Importer\Testing;

use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Storage\StorageReader;

class ImporterTestHelpers
{
    public static function getTempDir(): string
    {
        static $tempDir = null;
        
        if ($tempDir === null) {
            $tempDir = sys_get_temp_dir() . '/importer_tests_' . uniqid();
            
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
        }
        
        return $tempDir;
    }
    
    public static function cleanupTempDir(): void
    {
        $tempDir = self::getTempDir();
        
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    public static function createTempCsvFile(array $data, array $headers = null, string $filename = null): string
    {
        $filename = $filename ?: 'test_' . uniqid() . '.csv';
        $filepath = self::getTempDir() . '/' . $filename;
        
        $handle = fopen($filepath, 'w');
        
        if ($headers) {
            fputcsv($handle, $headers);
        }
        
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        
        return $filepath;
    }
    
    public static function createLargeCsvFile(int $rows, array $headers = null, string $filename = null): string
    {
        $filename = $filename ?: 'large_test_' . uniqid() . '.csv';
        $filepath = self::getTempDir() . '/' . $filename;
        
        $handle = fopen($filepath, 'w');
        
        $headers = $headers ?: ['id', 'name', 'email', 'status'];
        fputcsv($handle, $headers);
        
        for ($i = 1; $i <= $rows; $i++) {
            fputcsv($handle, [
                $i,
                "User {$i}",
                "user{$i}@example.com",
                $i % 2 === 0 ? 'active' : 'inactive'
            ]);
        }
        
        fclose($handle);
        
        return $filepath;
    }
    
    public static function createInvalidCsvFile(string $filename = null): string
    {
        $filename = $filename ?: 'invalid_test_' . uniqid() . '.csv';
        $filepath = self::getTempDir() . '/' . $filename;
        
        $content = "id,name,email\n";
        $content .= "1,John Doe,john@example.com\n";
        $content .= "2,Jane,invalid-email\n"; // Invalid email
        $content .= "3,,jane@example.com\n"; // Missing name
        $content .= "4,Bob Smith,bob@example.com,extra,columns\n"; // Extra columns
        $content .= "\n"; // Empty line
        $content .= "5,Alice,alice@example.com\n";
        
        file_put_contents($filepath, $content);
        
        return $filepath;
    }
    
    public static function assertImportSuccessful(ImportResult $result, string $message = ''): void
    {
        $this->assertTrue($result->success, $message ?: 'Import should be successful');
        $this->assertEmpty($result->errors, 'Import should have no errors: ' . implode(', ', $result->errors));
    }
    
    public static function assertImportFailed(ImportResult $result, string $message = ''): void
    {
        $this->assertFalse($result->success, $message ?: 'Import should have failed');
    }
    
    public static function assertImportProcessed(ImportResult $result, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, $result->processed, $message ?: "Expected {$expectedCount} rows to be processed");
    }
    
    public static function assertImportImported(ImportResult $result, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, $result->imported, $message ?: "Expected {$expectedCount} rows to be imported");
    }
    
    public static function assertImportFailedCount(ImportResult $result, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, $result->failed, $message ?: "Expected {$expectedCount} rows to fail");
    }
    
    public static function assertImportErrors(ImportResult $result, int $expectedCount, string $message = ''): void
    {
        $this->assertCount($expectedCount, $result->errors, $message ?: "Expected {$expectedCount} errors");
    }
    
    public static function assertStorageContains(StorageReader $reader, array $expectedData, string $message = ''): void
    {
        $actualData = iterator_to_array($reader->all());
        $this->assertEquals($expectedData, $actualData, $message ?: 'Storage should contain expected data');
    }
    
    public static function assertStorageHasHeaders(StorageReader $reader, array $expectedHeaders, string $message = ''): void
    {
        $this->assertEquals($expectedHeaders, $reader->getHeaders(), $message ?: 'Storage should have expected headers');
    }
    
    public static function assertStorageCount(StorageReader $reader, int $expectedCount, string $message = ''): void
    {
        $this->assertEquals($expectedCount, $reader->count(), $message ?: "Storage should contain {$expectedCount} rows");
    }
    
    public static function assertPipelineState(string $stateHash, array $expectedState): void
    {
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        $this->assertFileExists($statePath, 'Pipeline state file should exist');
        
        $actualState = json_decode(file_get_contents($statePath), true);
        
        foreach ($expectedState as $key => $value) {
            $this->assertEquals($value, $actualState[$key] ?? null, "State key '{$key}' should match expected value");
        }
    }
    
    public static function assertPipelineProgress(string $stateHash, float $expectedPercentage, float $tolerance = 1.0): void
    {
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        $state = json_decode(file_get_contents($statePath), true);
        
        $actualPercentage = $state['processing_progress'] ?? 0;
        $this->assertEqualsWithDelta($expectedPercentage, $actualPercentage, $tolerance, 
            "Pipeline progress should be approximately {$expectedPercentage}%");
    }
    
    public static function createTestData(int $rows = 10): array
    {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = [
                'id' => $i,
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'age' => rand(18, 80),
                'status' => $i % 2 === 0 ? 'active' : 'inactive'
            ];
        }
        return $data;
    }
    
    
    public static function pauseAndResumeImport(callable $importCallback, string $pauseAfter = 'parse_headers'): ImportResult
    {
        // Start import
        $result = $importCallback();
        
        // Get state hash and simulate pause
        $stateHash = $result->meta['state_hash'] ?? null;
        $this->assertNotNull($stateHash, 'Import should have state hash');
        
        // Modify state to paused
        $statePath = storage_path('pipeline/' . $stateHash . '.json');
        $state = json_decode(file_get_contents($statePath), true);
        $state['status'] = 'paused';
        file_put_contents($statePath, json_encode($state));
        
        // Resume import
        return $importCallback();
    }
}