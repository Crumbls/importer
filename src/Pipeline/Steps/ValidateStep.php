<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Crumbls\Importer\Adapters\Traits\HasFileValidation;
use Crumbls\Importer\Exceptions\FileNotFoundException;
use Crumbls\Importer\Exceptions\FileNotReadableException;
use Crumbls\Importer\Exceptions\FileTooLargeException;

class ValidateStep extends PipelineStep
{
    use HasFileValidation;
    
    protected bool $required = true;
    
    public function execute(string $source, array $options, array $driverConfig, PipelineContext $context): array
    {
        try {
            // Check if file exists
            if (!file_exists($source)) {
                throw new FileNotFoundException($source);
            }
            
            // Check if file is readable
            if (!is_readable($source)) {
                throw new FileNotReadableException($source);
            }
            
            // Get detailed file information
            $fileInfo = $this->getFileInfo($source);
            
            // Check file size limits if configured
            $maxSize = $driverConfig['max_file_size'] ?? null;
            if ($maxSize && $fileInfo['size'] > $maxSize) {
                throw new FileTooLargeException($source, $fileInfo['size'], $maxSize);
            }
            
            // Validate file extension if driver specifies allowed extensions
            $allowedExtensions = $driverConfig['allowed_extensions'] ?? null;
            if ($allowedExtensions && !$this->validateFileExtension($source, $allowedExtensions)) {
                return $this->formatErrorResult('Invalid file extension', [
                    'extension' => $fileInfo['extension'],
                    'allowed' => $allowedExtensions
                ]);
            }
            
            $result = $this->formatSuccessResult([
                'file_info' => $fileInfo,
                'validation_passed' => true
            ]);
            
            $this->recordStepCompletion($context, $result);
            return $result;
            
        } catch (\Crumbls\Importer\Exceptions\ImporterException $e) {
            return $this->formatErrorResult($e->getMessage(), $e->getContext());
        } catch (\Exception $e) {
            return $this->formatErrorResult('Validation error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}