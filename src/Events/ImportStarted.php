<?php

namespace Crumbls\Importer\Events;

class ImportStarted extends ImportEvent
{
    public readonly string $driver;
    public readonly array $options;
    public readonly array $fileInfo;
    
    public function __construct(
        string $importId,
        string $source,
        string $driver,
        array $options = [],
        array $fileInfo = [],
        array $context = []
    ) {
        parent::__construct($importId, $source, $context);
        
        $this->driver = $driver;
        $this->options = $options;
        $this->fileInfo = $fileInfo;
    }
    
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'driver' => $this->driver,
            'options' => $this->options,
            'file_info' => $this->fileInfo
        ]);
    }
}