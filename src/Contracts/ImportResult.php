<?php

namespace Crumbls\Importer\Contracts;

class ImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $processed,
        public readonly int $imported,
        public readonly int $failed,
        public readonly array $errors = [],
        public readonly array $meta = []
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'processed' => $this->processed,
            'imported' => $this->imported,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'meta' => $this->meta,
        ];
    }
}
