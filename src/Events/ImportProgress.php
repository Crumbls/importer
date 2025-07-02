<?php

namespace Crumbls\Importer\Events;

class ImportProgress extends ImportEvent
{
    public readonly int $processed;
    public readonly int $total;
    public readonly float $percentage;
    public readonly string $currentStep;
    public readonly array $statistics;
    
    public function __construct(
        string $importId,
        string $source,
        int $processed,
        int $total,
        string $currentStep,
        array $statistics = [],
        array $context = []
    ) {
        parent::__construct($importId, $source, $context);
        
        $this->processed = $processed;
        $this->total = $total;
        $this->percentage = $total > 0 ? ($processed / $total) * 100 : 0;
        $this->currentStep = $currentStep;
        $this->statistics = $statistics;
    }
    
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'progress' => [
                'processed' => $this->processed,
                'total' => $this->total,
                'percentage' => round($this->percentage, 2)
            ],
            'current_step' => $this->currentStep,
            'statistics' => $this->statistics
        ]);
    }
}