<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Support\DelimiterDetector;

class DetectDelimiterStep extends PipelineStep
{
    protected bool $required = false;
    protected array $dependencies = ['validate'];
    
    public function execute(string $source, array $options, array $driverConfig, PipelineContext $context): array
    {
        try {
            // Skip if delimiter is already configured and auto-detection is disabled
            $autoDetect = $driverConfig['auto_detect_delimiter'] ?? true;
            $configuredDelimiter = $driverConfig['delimiter'] ?? null;
            
            if (!$autoDetect && $configuredDelimiter) {
                $result = $this->formatSuccessResult([
                    'delimiter' => $configuredDelimiter,
                    'method' => 'configured',
                    'auto_detected' => false
                ]);
                
                $this->recordStepCompletion($context, $result);
                return $result;
            }
            
            // Auto-detect delimiter
            $detectedDelimiter = DelimiterDetector::detect($source);
            
            if (!$detectedDelimiter) {
                // Fallback to configured delimiter or default
                $delimiter = $configuredDelimiter ?? ',';
                
                $result = $this->formatSuccessResult([
                    'delimiter' => $delimiter,
                    'method' => 'fallback',
                    'auto_detected' => false,
                    'warning' => 'Could not auto-detect delimiter, using fallback'
                ]);
            } else {
                $result = $this->formatSuccessResult([
                    'delimiter' => $detectedDelimiter,
                    'method' => 'auto_detected',
                    'auto_detected' => true,
                    'delimiter_name' => DelimiterDetector::getDelimiterName($detectedDelimiter)
                ]);
            }
            
            $this->recordStepCompletion($context, $result);
            return $result;
            
        } catch (\Exception $e) {
            return $this->formatErrorResult('Delimiter detection error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    public function canSkip(PipelineContext $context): bool
    {
        // This step can be skipped for non-CSV files
        return parent::canSkip($context);
    }
}