<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Contracts\ImporterDriverContract;
use Crumbls\Importer\Contracts\ImportResult;
use Crumbls\Importer\Contracts\MigrationAdapter;
use Crumbls\Importer\Pipeline\ImportPipeline;
use Crumbls\Importer\Storage\TemporaryStorageManager;
use Crumbls\Importer\Storage\StorageReader;
use Crumbls\Importer\Xml\XmlParser;
use Crumbls\Importer\Xml\XmlSchema;

class XmlDriver implements ImporterDriverContract
{
    protected array $config;
    protected ImportPipeline $pipeline;
    protected TemporaryStorageManager $storageManager;
    protected string $storageDriver = 'multi_table_sqlite';
    protected array $storageConfig = [];
    protected XmlSchema $schema;
    protected array $enabledEntities = [];
    protected int $chunkSize = 100;
    protected ?MigrationAdapter $migrationAdapter = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'schema' => null,
            'enabled_entities' => [],
            'chunk_size' => 100
        ], $config);
        
        $this->schema = $this->config['schema'] ?? new XmlSchema();
        $this->enabledEntities = $this->config['enabled_entities'];
        $this->chunkSize = $this->config['chunk_size'];
        
        $this->pipeline = new ImportPipeline();
        $this->storageManager = new TemporaryStorageManager();
        $this->setupPipeline();
    }

    public function import(string $source, array $options = []): ImportResult
    {
        $driverConfig = array_merge($this->config, [
            'storage_driver' => $this->storageDriver,
            'storage_config' => $this->storageConfig,
            'schema' => $this->schema,
            'enabled_entities' => $this->enabledEntities,
            'chunk_size' => $this->chunkSize
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
        if (!file_exists($source) || !is_readable($source)) {
            return false;
        }
        
        return $this->isValidXml($source);
    }

    public function preview(string $source, int $limit = 10): array
    {
        if (!$this->validate($source)) {
            return [];
        }
        
        try {
            $parser = XmlParser::fromFile($source);
            $parser->registerNamespaces($this->schema->getNamespaces());
            
            $preview = [
                'document_info' => $parser->getDocumentInfo(),
                'validation' => $parser->validateStructure($this->schema->getRequiredXpaths()),
                'entities' => []
            ];
            
            foreach ($this->schema->getEnabledEntities($this->enabledEntities) as $entityName => $config) {
                $entityPreview = $parser->preview(
                    $config['xpath'],
                    $config['fields'],
                    min($limit, 3) // Limit per entity
                );
                
                $preview['entities'][$entityName] = [
                    'xpath' => $config['xpath'],
                    'sample_records' => $entityPreview,
                    'estimated_count' => count($parser->xpath($config['xpath']))
                ];
            }
            
            return $preview;
            
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to preview XML: ' . $e->getMessage()
            ];
        }
    }
    
    public function withSchema(XmlSchema $schema): self
    {
        $this->schema = $schema;
        return $this;
    }
    
    public function enableEntity(string $entity, bool $enabled = true): self
    {
        $this->enabledEntities[$entity] = $enabled;
        return $this;
    }
    
    public function enableEntities(array $entities): self
    {
        $this->enabledEntities = array_merge($this->enabledEntities, $entities);
        return $this;
    }
    
    public function onlyEntities(array $entities): self
    {
        // Disable all first
        foreach (array_keys($this->schema->getEntities()) as $entity) {
            $this->enabledEntities[$entity] = false;
        }
        
        // Enable only specified ones
        foreach ($entities as $entity) {
            $this->enabledEntities[$entity] = true;
        }
        
        return $this;
    }
    
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
    
    public function xpath(string $xpath): self
    {
        // For custom single-entity extraction
        $this->schema->addEntity('custom', [
            'xpath' => $xpath,
            'table' => 'data',
            'fields' => []
        ]);
        
        $this->enabledEntities = ['custom' => true];
        return $this;
    }
    
    public function mapField(string $name, string $xpath): self
    {
        $entities = $this->schema->getEntities();
        if (isset($entities['custom'])) {
            $entities['custom']['fields'][$name] = $xpath;
            $this->schema = new XmlSchema([
                'namespaces' => $this->schema->getNamespaces(),
                'entities' => $entities
            ]);
        }
        
        return $this;
    }
    
    public function useSqliteStorage(array $config = []): self
    {
        $this->storageDriver = 'multi_table_sqlite';
        $this->storageConfig = $config;
        return $this;
    }
    
    public function getStorageReader(string $table = 'data'): ?StorageReader
    {
        $storage = $this->pipeline->getContext()->get('temporary_storage');
        return $storage ? new StorageReader($storage, $table) : null;
    }
    
    public function migrateTo(MigrationAdapter $adapter): self
    {
        $this->migrationAdapter = $adapter;
        return $this;
    }
    
    public function plan(string $source): ?\Crumbls\Importer\Contracts\MigrationPlan
    {
        if (!$this->migrationAdapter) {
            throw new \RuntimeException('No migration adapter configured. Call migrateTo() first.');
        }
        
        // Extract data first
        $extractResult = $this->import($source);
        if (!$extractResult->success) {
            throw new \RuntimeException('Extract failed: ' . implode(', ', $extractResult->errors));
        }
        
        // Get extracted data from storage
        $extractedData = $this->getExtractedData();
        
        return $this->migrationAdapter->plan($extractedData);
    }
    
    public function validateMigration(string $source): \Crumbls\Importer\Contracts\ValidationResult
    {
        $plan = $this->plan($source);
        return $this->migrationAdapter->validate($plan);
    }
    
    public function dryRun(string $source): \Crumbls\Importer\Contracts\DryRunResult
    {
        $plan = $this->plan($source);
        return $this->migrationAdapter->dryRun($plan);
    }
    
    public function migrate(string $source, array $options = []): \Crumbls\Importer\Contracts\MigrationResult
    {
        $plan = $this->plan($source);
        return $this->migrationAdapter->migrate($plan, $options);
    }
    
    protected function getExtractedData(): array
    {
        $extractedData = [];
        
        // Get data from all enabled entities
        foreach ($this->enabledEntities as $entityName => $enabled) {
            if (!$enabled) {
                continue;
            }
            
            $storage = $this->getStorageReader($this->schema->getEntity($entityName)['table'] ?? $entityName);
            if ($storage) {
                $extractedData[$entityName] = iterator_to_array($storage->chunk(1000));
            }
        }
        
        return $extractedData;
    }
    
    protected function setupPipeline(): void
    {
        $this->pipeline
            ->addStep('validate')
            ->addStep('parse_xml_structure')
            ->addStep('create_storage')
            ->addStep('extract_entities');
    }
    
    protected function isValidXml(string $source): bool
    {
        try {
            $xml = simplexml_load_file($source);
            return $xml !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
}