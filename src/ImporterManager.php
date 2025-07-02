<?php

namespace Crumbls\Importer;

use Illuminate\Support\Manager;
use Crumbls\Importer\Support\QueuedImporter;
use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Crumbls\Importer\Drivers\WpxmlDriver;

class ImporterManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config->get('importer.default', 'csv');
    }
    
    public function driver($driver = null)
    {
        // Always create new instances for testing
        $driver = $driver ?: $this->getDefaultDriver();
        
        return match ($driver) {
            'csv' => $this->createCsvDriver(),
            'xml' => $this->createXmlDriver(),
            'wpxml' => $this->createWpxmlDriver(),
            'sql' => $this->createSqlDriver(),
            default => throw new \InvalidArgumentException("Driver [{$driver}] not supported.")
        };
    }

    protected function createCsvDriver()
    {
        return new CsvDriver($this->config->get('importer.drivers.csv', []));
    }

    protected function createXmlDriver()
    {
        return new XmlDriver($this->config->get('importer.drivers.xml', []));
    }

    protected function createWpxmlDriver()
    {
        return new WpxmlDriver($this->config->get('importer.drivers.wpxml', []));
    }

    protected function createSqlDriver()
    {
        // TODO: Implement SQL driver later
        throw new \Exception('SQL driver not implemented yet');
    }
    
    /**
     * Create a queued importer instance
     */
    public function queued(): QueuedImporter
    {
        return new QueuedImporter();
    }
    
    /**
     * Get import result by ID
     */
    public function getResult(string $importId): ?array
    {
        return QueuedImporter::getResult($importId);
    }
    
    /**
     * Check import status
     */
    public function getStatus(string $importId): ?string
    {
        return QueuedImporter::getStatus($importId);
    }
}
