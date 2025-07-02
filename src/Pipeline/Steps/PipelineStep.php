<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;

abstract class PipelineStep
{
    protected string $name;
    protected bool $required = true;
    protected array $dependencies = [];
    
    public function __construct(string $name = null)
    {
        $this->name = $name ?? $this->getDefaultName();
    }
    
    abstract public function execute(string $source, array $options, array $driverConfig, PipelineContext $context): array;
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function isRequired(): bool
    {
        return $this->required;
    }
    
    public function getDependencies(): array
    {
        return $this->dependencies;
    }
    
    public function canSkip(PipelineContext $context): bool
    {
        return !$this->required;
    }
    
    public function shouldExecute(PipelineContext $context): bool
    {
        // Check if all dependencies are satisfied
        foreach ($this->dependencies as $dependency) {
            if (!$context->has("step_completed.{$dependency}")) {
                return false;
            }
        }
        
        return true;
    }
    
    protected function getDefaultName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/Step$/', '', $className));
    }
    
    protected function recordStepCompletion(PipelineContext $context, array $result): void
    {
        $context->set("step_completed.{$this->name}", true);
        $context->set("step_result.{$this->name}", $result);
        $context->set("step_timestamp.{$this->name}", microtime(true));
    }
    
    protected function getStepResult(string $stepName, PipelineContext $context): ?array
    {
        return $context->get("step_result.{$stepName}");
    }
    
    protected function formatSuccessResult(array $data = []): array
    {
        return array_merge([
            'success' => true,
            'step' => $this->name,
            'timestamp' => microtime(true)
        ], $data);
    }
    
    protected function formatErrorResult(string $error, array $context = []): array
    {
        return [
            'success' => false,
            'step' => $this->name,
            'error' => $error,
            'context' => $context,
            'timestamp' => microtime(true)
        ];
    }
}