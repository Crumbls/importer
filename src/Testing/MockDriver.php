<?php

namespace Crumbls\Importer\Testing;

use Crumbls\Importer\Contracts\ImporterDriverContract;
use Crumbls\Importer\Contracts\ImportResult;

class MockDriver implements ImporterDriverContract
{
    protected array $config;
    protected bool $shouldFail = false;
    protected array $mockData = [];
    protected array $mockErrors = [];
    protected int $processedCount = 0;
    protected int $importedCount = 0;
    protected int $failedCount = 0;
    protected bool $tempStorageEnabled = false;
    protected array $importCalls = [];
    protected array $validationCalls = [];
    protected array $previewCalls = [];
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    public function import(string $source, array $options = []): ImportResult
    {
        $this->importCalls[] = [
            'source' => $source,
            'options' => $options,
            'timestamp' => time()
        ];
        
        if ($this->shouldFail) {
            return new ImportResult(
                success: false,
                processed: 0,
                imported: 0,
                failed: 1,
                errors: !empty($this->mockErrors) ? $this->mockErrors : ['Mock driver configured to fail'],
                meta: ['mock' => true]
            );
        }
        
        return new ImportResult(
            success: true,
            processed: $this->processedCount,
            imported: $this->importedCount,
            failed: $this->failedCount,
            errors: $this->mockErrors,
            meta: [
                'mock' => true,
                'temp_storage' => $this->tempStorageEnabled,
                'source' => $source,
                'options' => $options
            ]
        );
    }
    
    public function withTempStorage(): self
    {
        $this->tempStorageEnabled = true;
        return $this;
    }
    
    public function validate(string $source): bool
    {
        $this->validationCalls[] = [
            'source' => $source,
            'timestamp' => time()
        ];
        
        return !$this->shouldFail && file_exists($source);
    }
    
    public function preview(string $source, int $limit = 10): array
    {
        $this->previewCalls[] = [
            'source' => $source,
            'limit' => $limit,
            'timestamp' => time()
        ];
        
        if ($this->shouldFail) {
            return ['error' => 'Mock driver configured to fail'];
        }
        
        return array_slice($this->mockData, 0, $limit);
    }
    
    // Mock configuration methods
    
    public function shouldFail(bool $fail = true): self
    {
        $this->shouldFail = $fail;
        return $this;
    }
    
    public function withMockData(array $data): self
    {
        $this->mockData = $data;
        return $this;
    }
    
    public function withProcessedCount(int $count): self
    {
        $this->processedCount = $count;
        return $this;
    }
    
    public function withImportedCount(int $count): self
    {
        $this->importedCount = $count;
        return $this;
    }
    
    public function withFailedCount(int $count): self
    {
        $this->failedCount = $count;
        return $this;
    }
    
    public function withErrors(array $errors): self
    {
        $this->mockErrors = $errors;
        return $this;
    }
    
    // Assertion helpers for testing
    
    public function getImportCalls(): array
    {
        return $this->importCalls;
    }
    
    public function getValidationCalls(): array
    {
        return $this->validationCalls;
    }
    
    public function getPreviewCalls(): array
    {
        return $this->previewCalls;
    }
    
    public function wasImportCalled(): bool
    {
        return !empty($this->importCalls);
    }
    
    public function wasValidationCalled(): bool
    {
        return !empty($this->validationCalls);
    }
    
    public function wasPreviewCalled(): bool
    {
        return !empty($this->previewCalls);
    }
    
    public function getLastImportCall(): ?array
    {
        return end($this->importCalls) ?: null;
    }
    
    public function getImportCallCount(): int
    {
        return count($this->importCalls);
    }
    
    public function reset(): self
    {
        $this->importCalls = [];
        $this->validationCalls = [];
        $this->previewCalls = [];
        $this->shouldFail = false;
        $this->mockData = [];
        $this->mockErrors = [];
        $this->processedCount = 0;
        $this->importedCount = 0;
        $this->failedCount = 0;
        $this->tempStorageEnabled = false;
        
        return $this;
    }
}