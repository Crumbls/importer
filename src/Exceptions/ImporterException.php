<?php

namespace Crumbls\Importer\Exceptions;

abstract class ImporterException extends \Exception
{
    protected array $context = [];
    protected array $recoveryOptions = [];
    protected bool $isRetryable = false;
    
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }
    
    public function getContext(): array
    {
        return array_merge($this->context, [
            'exception_class' => static::class,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true)
        ]);
    }
    
    public function getRecoveryOptions(): array
    {
        return $this->recoveryOptions;
    }
    
    public function isRetryable(): bool
    {
        return $this->isRetryable;
    }
    
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->getContext(),
            'recovery_options' => $this->getRecoveryOptions(),
            'is_retryable' => $this->isRetryable(),
            'trace' => $this->getTraceAsString()
        ];
    }
}