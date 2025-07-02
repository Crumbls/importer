<?php

namespace Crumbls\Importer\Events;

class ImportFailed extends ImportEvent
{
    public readonly \Throwable $exception;
    public readonly string $step;
    public readonly array $recoveryOptions;
    
    public function __construct(
        string $importId,
        string $source,
        \Throwable $exception,
        string $step = 'unknown',
        array $recoveryOptions = [],
        array $context = []
    ) {
        parent::__construct($importId, $source, $context);
        
        $this->exception = $exception;
        $this->step = $step;
        $this->recoveryOptions = $recoveryOptions;
    }
    
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'error' => [
                'message' => $this->exception->getMessage(),
                'type' => get_class($this->exception),
                'code' => $this->exception->getCode(),
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine()
            ],
            'step' => $this->step,
            'recovery_options' => $this->recoveryOptions
        ]);
    }
}