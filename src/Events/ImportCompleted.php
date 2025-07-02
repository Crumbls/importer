<?php

namespace Crumbls\Importer\Events;

use Crumbls\Importer\Contracts\ImportResult;

class ImportCompleted extends ImportEvent
{
    public readonly ImportResult $result;
    public readonly float $duration;
    public readonly array $statistics;
    
    public function __construct(
        string $importId,
        string $source,
        ImportResult $result,
        float $startTime,
        array $statistics = [],
        array $context = []
    ) {
        parent::__construct($importId, $source, $context);
        
        $this->result = $result;
        $this->duration = $this->timestamp - $startTime;
        $this->statistics = $statistics;
    }
    
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'result' => [
                'processed' => $this->result->getProcessed(),
                'imported' => $this->result->getImported(),
                'failed' => $this->result->getFailed(),
                'errors' => $this->result->getErrors()
            ],
            'duration' => $this->duration,
            'statistics' => $this->statistics
        ]);
    }
}