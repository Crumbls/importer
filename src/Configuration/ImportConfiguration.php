<?php

namespace Crumbls\Importer\Configuration;

class ImportConfiguration extends BaseConfiguration
{
    protected function getDefaults(): array
    {
        return match($this->environment) {
            'production' => [
                'chunk_size' => 1000,
                'memory_limit' => '512M',
                'timeout' => 300,
                'storage' => [
                    'driver' => 'sqlite',
                    'config' => []
                ],
                'validation' => [
                    'enabled' => true,
                    'skip_invalid_rows' => false,
                    'strict_mode' => true,
                    'rules' => []
                ],
                'performance' => [
                    'throttle' => [
                        'max_rows_per_second' => 0,
                        'max_chunks_per_minute' => 0
                    ],
                    'auto_optimize' => true,
                    'memory_threshold' => 0.8
                ],
                'columns' => [
                    'clean_names' => true,
                    'explicit_columns' => [],
                    'mappings' => []
                ],
                'format' => [
                    'delimiter' => ',',
                    'enclosure' => '"',
                    'escape' => '\\',
                    'has_headers' => true,
                    'auto_detect_delimiter' => true,
                    'encoding' => 'UTF-8'
                ],
                'pipeline' => [
                    'resume_enabled' => true,
                    'state_file_ttl' => 86400, // 24 hours
                    'checkpoint_frequency' => 1000
                ]
            ],
            'staging' => [
                'chunk_size' => 500,
                'memory_limit' => '256M',
                'timeout' => 180,
                'storage' => [
                    'driver' => 'memory',
                    'config' => []
                ],
                'validation' => [
                    'enabled' => true,
                    'skip_invalid_rows' => true,
                    'strict_mode' => false,
                    'rules' => []
                ],
                'performance' => [
                    'throttle' => [
                        'max_rows_per_second' => 0,
                        'max_chunks_per_minute' => 0
                    ],
                    'auto_optimize' => true,
                    'memory_threshold' => 0.7
                ],
                'columns' => [
                    'clean_names' => true,
                    'explicit_columns' => [],
                    'mappings' => []
                ],
                'format' => [
                    'delimiter' => ',',
                    'enclosure' => '"',
                    'escape' => '\\',
                    'has_headers' => true,
                    'auto_detect_delimiter' => true,
                    'encoding' => 'UTF-8'
                ],
                'pipeline' => [
                    'resume_enabled' => true,
                    'state_file_ttl' => 3600, // 1 hour
                    'checkpoint_frequency' => 500
                ]
            ],
            'development', 'testing' => [
                'chunk_size' => 100,
                'memory_limit' => '128M',
                'timeout' => 60,
                'storage' => [
                    'driver' => 'memory',
                    'config' => []
                ],
                'validation' => [
                    'enabled' => false,
                    'skip_invalid_rows' => true,
                    'strict_mode' => false,
                    'rules' => []
                ],
                'performance' => [
                    'throttle' => [
                        'max_rows_per_second' => 0,
                        'max_chunks_per_minute' => 0
                    ],
                    'auto_optimize' => false,
                    'memory_threshold' => 0.9
                ],
                'columns' => [
                    'clean_names' => true,
                    'explicit_columns' => [],
                    'mappings' => []
                ],
                'format' => [
                    'delimiter' => ',',
                    'enclosure' => '"',
                    'escape' => '\\',
                    'has_headers' => true,
                    'auto_detect_delimiter' => true,
                    'encoding' => 'UTF-8'
                ],
                'pipeline' => [
                    'resume_enabled' => false,
                    'state_file_ttl' => 300, // 5 minutes
                    'checkpoint_frequency' => 100
                ]
            ]
        };
    }
    
    protected function getValidationRules(): array
    {
        return [
            'chunk_size' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10000
            ],
            'memory_limit' => [
                'type' => 'string'
            ],
            'timeout' => [
                'type' => 'integer',
                'min' => 1
            ],
            'storage.driver' => [
                'required' => true,
                'type' => 'string',
                'in' => ['memory', 'sqlite']
            ],
            'storage.config' => [
                'type' => 'array'
            ],
            'validation.enabled' => [
                'type' => 'boolean'
            ],
            'validation.skip_invalid_rows' => [
                'type' => 'boolean'
            ],
            'validation.strict_mode' => [
                'type' => 'boolean'
            ],
            'validation.rules' => [
                'type' => 'array'
            ],
            'performance.throttle.max_rows_per_second' => [
                'type' => 'integer',
                'min' => 0
            ],
            'performance.throttle.max_chunks_per_minute' => [
                'type' => 'integer',
                'min' => 0
            ],
            'performance.auto_optimize' => [
                'type' => 'boolean'
            ],
            'performance.memory_threshold' => [
                'type' => 'float',
                'min' => 0.1,
                'max' => 1.0
            ],
            'columns.clean_names' => [
                'type' => 'boolean'
            ],
            'columns.explicit_columns' => [
                'type' => 'array'
            ],
            'columns.mappings' => [
                'type' => 'array'
            ],
            'format.delimiter' => [
                'type' => 'string',
                'min' => 1,
                'max' => 1
            ],
            'format.enclosure' => [
                'type' => 'string',
                'min' => 1,
                'max' => 1
            ],
            'format.escape' => [
                'type' => 'string',
                'min' => 1,
                'max' => 1
            ],
            'format.has_headers' => [
                'type' => 'boolean'
            ],
            'format.auto_detect_delimiter' => [
                'type' => 'boolean'
            ],
            'format.encoding' => [
                'type' => 'string'
            ],
            'pipeline.resume_enabled' => [
                'type' => 'boolean'
            ],
            'pipeline.state_file_ttl' => [
                'type' => 'integer',
                'min' => 1
            ],
            'pipeline.checkpoint_frequency' => [
                'type' => 'integer',
                'min' => 1
            ]
        ];
    }
    
    // Fluent configuration methods
    public function chunkSize(int $size): static
    {
        return $this->set('chunk_size', $size);
    }
    
    public function memoryLimit(string $limit): static
    {
        return $this->set('memory_limit', $limit);
    }
    
    public function timeout(int $seconds): static
    {
        return $this->set('timeout', $seconds);
    }
    
    public function storage(string $driver, array $config = []): static
    {
        return $this->set('storage', ['driver' => $driver, 'config' => $config]);
    }
    
    public function useMemoryStorage(): static
    {
        return $this->storage('memory');
    }
    
    public function useSqliteStorage(array $config = []): static
    {
        return $this->storage('sqlite', $config);
    }
    
    public function validation(bool $enabled = true): static
    {
        return $this->set('validation.enabled', $enabled);
    }
    
    public function skipInvalidRows(bool $skip = true): static
    {
        return $this->set('validation.skip_invalid_rows', $skip);
    }
    
    public function strictMode(bool $strict = true): static
    {
        return $this->set('validation.strict_mode', $strict);
    }
    
    public function validationRules(array $rules): static
    {
        return $this->set('validation.rules', $rules);
    }
    
    public function throttle(int $maxRowsPerSecond = 0, int $maxChunksPerMinute = 0): static
    {
        return $this->set('performance.throttle', [
            'max_rows_per_second' => $maxRowsPerSecond,
            'max_chunks_per_minute' => $maxChunksPerMinute
        ]);
    }
    
    public function autoOptimize(bool $optimize = true): static
    {
        return $this->set('performance.auto_optimize', $optimize);
    }
    
    public function memoryThreshold(float $threshold): static
    {
        return $this->set('performance.memory_threshold', $threshold);
    }
    
    public function columns(array $columns): static
    {
        return $this->set('columns.explicit_columns', $columns);
    }
    
    public function cleanColumnNames(bool $clean = true): static
    {
        return $this->set('columns.clean_names', $clean);
    }
    
    public function mapColumn(string $csvHeader, string $cleanName): static
    {
        $mappings = $this->get('columns.mappings', []);
        $mappings[$csvHeader] = $cleanName;
        return $this->set('columns.mappings', $mappings);
    }
    
    public function mapColumns(array $mappings): static
    {
        return $this->set('columns.mappings', $mappings);
    }
    
    public function delimiter(string $delimiter): static
    {
        return $this->set('format.delimiter', $delimiter);
    }
    
    public function enclosure(string $enclosure): static
    {
        return $this->set('format.enclosure', $enclosure);
    }
    
    public function escape(string $escape): static
    {
        return $this->set('format.escape', $escape);
    }
    
    public function withHeaders(bool $hasHeaders = true): static
    {
        return $this->set('format.has_headers', $hasHeaders);
    }
    
    public function autoDetectDelimiter(bool $autoDetect = true): static
    {
        return $this->set('format.auto_detect_delimiter', $autoDetect);
    }
    
    public function encoding(string $encoding): static
    {
        return $this->set('format.encoding', $encoding);
    }
    
    public function resumeEnabled(bool $enabled = true): static
    {
        return $this->set('pipeline.resume_enabled', $enabled);
    }
    
    public function stateFileTtl(int $seconds): static
    {
        return $this->set('pipeline.state_file_ttl', $seconds);
    }
    
    public function checkpointFrequency(int $frequency): static
    {
        return $this->set('pipeline.checkpoint_frequency', $frequency);
    }
    
    // Static factory methods
    public static function production(array $config = []): static
    {
        return new static($config, 'production');
    }
    
    public static function staging(array $config = []): static
    {
        return new static($config, 'staging');
    }
    
    public static function development(array $config = []): static
    {
        return new static($config, 'development');
    }
    
    public static function testing(array $config = []): static
    {
        return new static($config, 'testing');
    }
}