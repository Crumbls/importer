<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Contracts\ImporterDriverContract;
use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Pipeline\ImportPipeline;
use Crumbls\Importer\Storage\TemporaryStorageManager;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Parser\StreamingCsvParser;
use Crumbls\Importer\RateLimit\RateLimiter;

class CsvDriver implements ImporterDriverContract
{
    protected array $config;
    protected ImportPipeline $pipeline;
    protected ?string $delimiter = null;
    protected ?string $enclosure = null;
    protected ?string $escape = null;
    protected bool $hasHeaders = true;
    protected bool $autoDetectDelimiter = true;
    protected TemporaryStorageManager $storageManager;
    protected string $storageDriver = 'memory';
    protected array $storageConfig = [];
    protected array $validationRules = [];
    protected bool $skipInvalidRows = false;
    protected int $maxErrors = 1000;
    protected int $chunkSize = 1000;
    protected ?RateLimiter $rateLimiter = null;
    protected int $maxRowsPerSecond = 0;
    protected int $maxChunksPerMinute = 0;
    protected ?array $columns = null;
    protected array $columnMapping = [];
    protected bool $autoDetectColumns = true;
    protected bool $cleanColumnNames = true;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
            'has_headers' => true,
            'auto_detect_delimiter' => true
        ], $config);
        
        $this->delimiter = $this->config['delimiter'] ?? null;
        $this->enclosure = $this->config['enclosure'] ?? null;
        $this->escape = $this->config['escape'] ?? null;
        $this->hasHeaders = $this->config['has_headers'] ?? true;
        $this->autoDetectDelimiter = $this->config['auto_detect_delimiter'] ?? true;
        
        $this->pipeline = new ImportPipeline();
        $this->storageManager = new TemporaryStorageManager();
        $this->setupPipeline();
    }

    public function import(string $source, array $options = []): ImportResult
    {
        $driverConfig = array_merge($this->config, [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'escape' => $this->escape,
            'has_headers' => $this->hasHeaders,
            'auto_detect_delimiter' => $this->autoDetectDelimiter,
            'storage_driver' => $this->storageDriver,
            'storage_config' => $this->storageConfig,
            'validation_rules' => $this->validationRules,
            'skip_invalid_rows' => $this->skipInvalidRows,
            'max_errors' => $this->maxErrors,
            'chunk_size' => $this->chunkSize,
            'rate_limiter' => $this->rateLimiter,
            'max_rows_per_second' => $this->maxRowsPerSecond,
            'max_chunks_per_minute' => $this->maxChunksPerMinute,
            'columns' => $this->columns,
            'column_mapping' => $this->columnMapping,
            'auto_detect_columns' => $this->autoDetectColumns,
            'clean_column_names' => $this->cleanColumnNames
        ]);
        
        $this->pipeline->setDriverConfig($driverConfig);
        return $this->pipeline->process($source, $options);
    }

    public function withTempStorage(): self
    {
        $this->pipeline->withTempStorage();
        return $this;
    }

    public function validate(string $source): bool
    {
        return file_exists($source) && is_readable($source);
    }

    public function preview(string $source, int $limit = 10): array
    {
        if (!$this->validate($source)) {
            return [];
        }
        
        try {
            $delimiter = $this->delimiter ?: $this->detectDelimiter($source);
            $enclosure = $this->enclosure ?: '"';
            $escape = $this->escape ?: '\\';
            
            $handle = fopen($source, 'r');
            if (!$handle) {
                return [];
            }
            
            $preview = [];
            $headers = null;
            
            if ($this->hasHeaders) {
                $headers = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
                $preview['headers'] = $headers ?: [];
            }
            
            $rows = [];
            $count = 0;
            
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false && $count < $limit) {
                $rows[] = $row;
                $count++;
            }
            
            fclose($handle);
            
            $preview['rows'] = $rows;
            $preview['total_rows_previewed'] = count($rows);
            $preview['delimiter'] = $delimiter;
            $preview['has_headers'] = $this->hasHeaders;
            
            return $preview;
            
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to preview CSV: ' . $e->getMessage()
            ];
        }
    }
    
    public function delimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;
        $this->autoDetectDelimiter = false;
        return $this;
    }
    
    public function enclosure(string $enclosure): self
    {
        $this->enclosure = $enclosure;
        return $this;
    }
    
    public function escape(string $escape): self
    {
        $this->escape = $escape;
        return $this;
    }
    
    public function withHeaders(bool $hasHeaders = true): self
    {
        $this->hasHeaders = $hasHeaders;
        return $this;
    }
    
    public function withoutHeaders(): self
    {
        return $this->withHeaders(false);
    }
    
    public function autoDetectDelimiter(bool $autoDetect = true): self
    {
        $this->autoDetectDelimiter = $autoDetect;
        return $this;
    }
    
    public function tab(): self
    {
        return $this->delimiter("\t");
    }
    
    public function semicolon(): self
    {
        return $this->delimiter(';');
    }
    
    public function pipe(): self
    {
        return $this->delimiter('|');
    }
    
    public function useMemoryStorage(): self
    {
        $this->storageDriver = 'memory';
        return $this;
    }
    
    public function useSqliteStorage(array $config = []): self
    {
        $this->storageDriver = 'sqlite';
        $this->storageConfig = $config;
        return $this;
    }
    
    public function storage(string $driver, array $config = []): self
    {
        $this->storageDriver = $driver;
        $this->storageConfig = $config;
        return $this;
    }
    
    public function getStorageReader(): ?StorageReader
    {
        $storage = $this->pipeline->getContext()->get('temporary_storage');
        return $storage ? new StorageReader($storage) : null;
    }
    
    public function withValidation(array $rules): self
    {
        $this->validationRules = $rules;
        return $this;
    }
    
    public function skipInvalidRows(bool $skip = true): self
    {
        $this->skipInvalidRows = $skip;
        return $this;
    }
    
    public function maxErrors(int $maxErrors): self
    {
        $this->maxErrors = $maxErrors;
        return $this;
    }
    
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
    
    public function throttle(int $maxRowsPerSecond = 0, int $maxChunksPerMinute = 0): self
    {
        $this->maxRowsPerSecond = $maxRowsPerSecond;
        $this->maxChunksPerMinute = $maxChunksPerMinute;
        
        if ($maxRowsPerSecond > 0 || $maxChunksPerMinute > 0) {
            $this->rateLimiter = new RateLimiter(
                max($maxRowsPerSecond ?: 1000000, $maxChunksPerMinute ?: 1000000),
                $maxChunksPerMinute > 0 ? 60 : 1
            );
        }
        
        return $this;
    }
    
    public function maxRowsPerSecond(int $limit): self
    {
        return $this->throttle($limit, $this->maxChunksPerMinute);
    }
    
    public function maxChunksPerMinute(int $limit): self
    {
        return $this->throttle($this->maxRowsPerSecond, $limit);
    }
    
    public function getRateLimiterStats(): ?array
    {
        return $this->rateLimiter?->getStats();
    }
    
    public function required(string $column): self
    {
        $this->addValidationRule($column, 'required', true);
        return $this;
    }
    
    public function numeric(string $column): self
    {
        $this->addValidationRule($column, 'numeric', true);
        return $this;
    }
    
    public function email(string $column): self
    {
        $this->addValidationRule($column, 'email', true);
        return $this;
    }
    
    public function minLength(string $column, int $length): self
    {
        $this->addValidationRule($column, 'min_length', $length);
        return $this;
    }
    
    public function maxLength(string $column, int $length): self
    {
        $this->addValidationRule($column, 'max_length', $length);
        return $this;
    }
    
    public function regex(string $column, string $pattern): self
    {
        $this->addValidationRule($column, 'regex', $pattern);
        return $this;
    }
    
    public function allowedValues(string $column, array $values): self
    {
        $this->addValidationRule($column, 'in', $values);
        return $this;
    }
    
    protected function addValidationRule(string $column, string $rule, $parameter): void
    {
        if (!isset($this->validationRules[$column])) {
            $this->validationRules[$column] = [];
        }
        $this->validationRules[$column][$rule] = $parameter;
    }
    
    protected function setupPipeline(): void
    {
        $this->pipeline
            ->addStep('validate')
            ->addStep('detect_delimiter')
            ->addStep('parse_headers')
            ->addStep('create_storage')
            ->addStep('process_rows');
    }
    
    public function columns(array $columns): self
    {
        $this->columns = $columns;
        $this->autoDetectColumns = false;
        return $this;
    }
    
    public function mapColumn(string $csvHeader, string $cleanName): self
    {
        $this->columnMapping[$csvHeader] = $cleanName;
        return $this;
    }
    
    public function mapColumns(array $mapping): self
    {
        $this->columnMapping = array_merge($this->columnMapping, $mapping);
        return $this;
    }
    
    public function autoDetectColumns(bool $autoDetect = true): self
    {
        $this->autoDetectColumns = $autoDetect;
        return $this;
    }
    
    public function cleanColumnNames(bool $clean = true): self
    {
        $this->cleanColumnNames = $clean;
        return $this;
    }
    
    public function withoutColumnCleaning(): self
    {
        return $this->cleanColumnNames(false);
    }
    
    
    protected function detectDelimiter(string $source, int $sampleSize = 1024): ?string
    {
        $delimiters = [',', ';', "\t", '|', ':'];
        $handle = fopen($source, 'r');
        
        if (!$handle) {
            return null;
        }
        
        $sample = fread($handle, $sampleSize);
        fclose($handle);
        
        if (!$sample) {
            return null;
        }
        
        $delimiterCounts = [];
        
        foreach ($delimiters as $delimiter) {
            $count = substr_count($sample, $delimiter);
            if ($count > 0) {
                $delimiterCounts[$delimiter] = $count;
            }
        }
        
        if (empty($delimiterCounts)) {
            return null;
        }
        
        arsort($delimiterCounts);
        return array_key_first($delimiterCounts);
    }
}
