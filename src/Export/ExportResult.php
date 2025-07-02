<?php

namespace Crumbls\Importer\Export;

use Crumbls\Importer\Contracts\ExportResult as ExportResultContract;

class ExportResult implements ExportResultContract
{
    protected int $exported;
    protected int $failed;
    protected array $errors;
    protected string $destination;
    protected string $format;
    protected array $stats;
    protected float $duration;
    
    public function __construct(
        int $exported = 0,
        int $failed = 0,
        array $errors = [],
        string $destination = '',
        string $format = 'csv',
        array $stats = [],
        float $duration = 0.0
    ) {
        $this->exported = $exported;
        $this->failed = $failed;
        $this->errors = $errors;
        $this->destination = $destination;
        $this->format = $format;
        $this->stats = $stats;
        $this->duration = $duration;
    }
    
    public function getExported(): int
    {
        return $this->exported;
    }
    
    public function getFailed(): int
    {
        return $this->failed;
    }
    
    public function isSuccessful(): bool
    {
        return $this->failed === 0 && !$this->hasErrors();
    }
    
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function getDestination(): string
    {
        return $this->destination;
    }
    
    public function getFormat(): string
    {
        return $this->format;
    }
    
    public function getStats(): array
    {
        return array_merge([
            'exported' => $this->exported,
            'failed' => $this->failed,
            'success_rate' => $this->getSuccessRate(),
            'destination' => $this->destination,
            'format' => $this->format,
            'duration' => $this->duration,
            'export_rate' => $this->getExportRate()
        ], $this->stats);
    }
    
    public function getDuration(): float
    {
        return $this->duration;
    }
    
    public function getSuccessRate(): float
    {
        $total = $this->exported + $this->failed;
        return $total > 0 ? ($this->exported / $total) * 100 : 100.0;
    }
    
    public function getExportRate(): float
    {
        return $this->duration > 0 ? $this->exported / $this->duration : 0;
    }
    
    public function toArray(): array
    {
        return [
            'exported' => $this->exported,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'destination' => $this->destination,
            'format' => $this->format,
            'duration' => $this->duration,
            'success_rate' => $this->getSuccessRate(),
            'export_rate' => $this->getExportRate(),
            'is_successful' => $this->isSuccessful(),
            'stats' => $this->stats
        ];
    }
}