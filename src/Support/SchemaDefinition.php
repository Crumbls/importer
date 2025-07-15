<?php

namespace Crumbls\Importer\Support;

class SchemaDefinition
{
    protected array $columns = [];
    protected array $indexes = [];
    
    public function __construct(protected string $tableName)
    {
    }
    
    public function column(string $name, string $type, array $options = []): self
    {
        $this->columns[$name] = array_merge([
            'type' => $type,
            'nullable' => false,
            'default' => null,
            'primary' => false,
        ], $options);
        
        return $this;
    }
    
    public function bigInteger(string $name, array $options = []): self
    {
        return $this->column($name, 'bigint', $options);
    }
    
    public function string(string $name, int $length = 255, array $options = []): self
    {
        return $this->column($name, 'string', array_merge(['length' => $length], $options));
    }
    
    public function text(string $name, array $options = []): self
    {
        return $this->column($name, 'text', $options);
    }
    
    public function longText(string $name, array $options = []): self
    {
        return $this->column($name, 'longtext', $options);
    }
    
    public function timestamp(string $name, array $options = []): self
    {
        return $this->column($name, 'timestamp', $options);
    }
    
    public function boolean(string $name, array $options = []): self
    {
        return $this->column($name, 'boolean', $options);
    }
    
    public function integer(string $name, array $options = []): self
    {
        return $this->column($name, 'integer', $options);
    }
    
    public function primary(string|array $columns): self
    {
        if (is_string($columns)) {
            $this->columns[$columns]['primary'] = true;
        } else {
            $this->indexes[] = [
                'type' => 'primary',
                'columns' => $columns
            ];
        }
        
        return $this;
    }
    
    public function index(string|array $columns, string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'index',
            'columns' => is_array($columns) ? $columns : [$columns],
            'name' => $name
        ];
        
        return $this;
    }
    
    public function timestamps(): self
    {
        return $this->timestamp('created_at')
                   ->timestamp('updated_at');
    }
    
    public function getTableName(): string
    {
        return $this->tableName;
    }
    
    public function getColumns(): array
    {
        return $this->columns;
    }
    
    public function getIndexes(): array
    {
        return $this->indexes;
    }
    
    public function toArray(): array
    {
        return [
            'table' => $this->tableName,
            'columns' => $this->columns,
            'indexes' => $this->indexes
        ];
    }
}