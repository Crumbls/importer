<?php

namespace Crumbls\Importer\Events;

abstract class ImportEvent
{
    public readonly string $importId;
    public readonly string $source;
    public readonly array $context;
    public readonly float $timestamp;
    
    public function __construct(
        string $importId,
        string $source,
        array $context = []
    ) {
        $this->importId = $importId;
        $this->source = $source;
        $this->context = $context;
        $this->timestamp = microtime(true);
    }
    
    public function getEventName(): string
    {
        return static::class;
    }
    
    public function toArray(): array
    {
        return [
            'import_id' => $this->importId,
            'source' => $this->source,
            'context' => $this->context,
            'timestamp' => $this->timestamp,
            'event' => $this->getEventName()
        ];
    }
}