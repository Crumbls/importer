<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Contracts\BaseDriverContract;
use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Pipeline\ImportPipeline;
use Crumbls\Importer\Storage\TemporaryStorageManager;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Parser\StreamingCsvParser;
use Crumbls\Importer\RateLimit\RateLimiter;
use Crumbls\Importer\Support\DelimiterDetector;
use Crumbls\Importer\Adapters\Traits\HasFileValidation;
use Crumbls\Importer\Adapters\Traits\HasDataTransformation;
use Crumbls\Importer\Adapters\Traits\HasStandardDriverMethods;
use Crumbls\Importer\Adapters\Traits\HasLaravelGeneration;
use Crumbls\Importer\Export\CsvExporter;
use Crumbls\Importer\Contracts\ExportResult as ExportResultContract;

class CsvDriver implements BaseDriverContract
{
    use HasFileValidation, HasDataTransformation, HasStandardDriverMethods, HasLaravelGeneration {
        HasFileValidation::getFileInfo insteadof HasStandardDriverMethods;
    }
    protected array $config;
    protected ImportPipeline $pipeline;
    protected ?string $delimiter = null;
    protected ?string $enclosure = null;
    protected ?string $escape = null;
    protected bool $hasHeaders = true;
    protected bool $autoDetectDelimiter = true;
    protected TemporaryStorageManager $storageManager;
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
        return $this->validateFile($source) && 
               $this->validateFileExtension($source, ['csv', 'txt', 'tsv']);
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
            
        } catch (\InvalidArgumentException $e) {
            return [
                'error' => 'Invalid CSV format: ' . $e->getMessage(),
                'error_type' => 'validation'
            ];
        } catch (\RuntimeException $e) {
            return [
                'error' => 'Cannot read CSV file: ' . $e->getMessage(),
                'error_type' => 'file_access'
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to preview CSV: ' . $e->getMessage(),
                'error_type' => 'unexpected'
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
        return DelimiterDetector::detect($source);
    }
    
    /**
     * Export data from storage to CSV file
     */
    public function exportToFile(string $destination, array $options = []): ExportResultContract
    {
        $reader = $this->getStorageReader();
        if (!$reader) {
            throw new \RuntimeException('No storage data available for export. Run import() first.');
        }
        
        $exporter = new CsvExporter();
        
        // Configure exporter based on driver settings
        if ($this->delimiter) {
            $exporter->delimiter($this->delimiter);
        }
        if ($this->enclosure) {
            $exporter->enclosure($this->enclosure);
        }
        if ($this->escape) {
            $exporter->escape($this->escape);
        }
        
        // Apply options
        if (isset($options['headers'])) {
            if ($options['headers'] === false) {
                $exporter->withoutHeaders();
            } else {
                $exporter->withHeaders(is_array($options['headers']) ? $options['headers'] : null);
            }
        }
        
        if (isset($options['column_mapping'])) {
            $exporter->mapColumns($options['column_mapping']);
        }
        
        if (isset($options['chunk_size'])) {
            $exporter->chunkSize($options['chunk_size']);
        }
        
        if (isset($options['transformer'])) {
            $exporter->transform($options['transformer']);
        }
        
        return $exporter->exportFromStorage($reader, $destination);
    }
    
    /**
     * Export array data to CSV file
     */
    public function exportArray(array $data, string $destination, array $options = []): ExportResultContract
    {
        $exporter = new CsvExporter();
        
        // Configure exporter based on driver settings
        if ($this->delimiter) {
            $exporter->delimiter($this->delimiter);
        }
        if ($this->enclosure) {
            $exporter->enclosure($this->enclosure);
        }
        if ($this->escape) {
            $exporter->escape($this->escape);
        }
        
        // Apply options
        if (isset($options['headers'])) {
            if ($options['headers'] === false) {
                $exporter->withoutHeaders();
            } else {
                $exporter->withHeaders(is_array($options['headers']) ? $options['headers'] : null);
            }
        }
        
        if (isset($options['column_mapping'])) {
            $exporter->mapColumns($options['column_mapping']);
        }
        
        if (isset($options['transformer'])) {
            $exporter->transform($options['transformer']);
        }
        
        return $exporter->exportFromArray($data, $destination);
    }
    
    /**
     * Get data as array for export
     */
    public function toArray(): array
    {
        $reader = $this->getStorageReader();
        if (!$reader) {
            return [];
        }
        
        $data = [];
        $reader->chunk(1000, function($rows) use (&$data) {
            $data = array_merge($data, $rows);
        });
        
        return $data;
    }
}
