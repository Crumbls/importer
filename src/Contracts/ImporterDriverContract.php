<?php

namespace Crumbls\Importer\Contracts;

interface ImporterDriverContract
{
    public function import(string $source, array $options = []): ImportResult;
    
    public function withTempStorage(): self;
    
    public function validate(string $source): bool;
    
    public function preview(string $source, int $limit = 10): array;
}
