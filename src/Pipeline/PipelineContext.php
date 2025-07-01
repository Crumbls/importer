<?php

namespace Crumbls\Importer\Pipeline;

class PipelineContext
{
    protected array $data = [];

    public function set(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function merge(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function forget(string $key): self
    {
        unset($this->data[$key]);
        return $this;
    }

    public static function fromArray(array $data): self
    {
        $context = new self();
        $context->data = $data;
        return $context;
    }
}
