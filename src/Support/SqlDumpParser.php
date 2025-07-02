<?php

namespace Crumbls\Importer\Support;

class SqlDumpParser
{
    protected array $tables = [];
    protected array $wordpressData = [];
    protected array $tableSchemas = [];
    protected array $statistics = [];
    protected string $wordpressPrefix = 'wp_';
    
    public function parseFile(string $sqlFilePath): array
    {
        if (!file_exists($sqlFilePath)) {
            throw new \InvalidArgumentException("SQL file not found: {$sqlFilePath}");
        }
        
        $this->reset();
        
        // Read file in chunks to handle large dumps
        $this->parseSqlFile($sqlFilePath);
        
        // Extract WordPress data from parsed tables
        $this->extractWordPressData();
        
        // Calculate statistics
        $this->calculateStatistics();
        
        return [
            'posts' => $this->wordpressData['posts'] ?? [],
            'postmeta' => $this->wordpressData['postmeta'] ?? [],
            'comments' => $this->wordpressData['comments'] ?? [],
            'users' => $this->wordpressData['users'] ?? [],
            'categories' => $this->wordpressData['terms'] ?? [],
            'tags' => $this->wordpressData['term_taxonomy'] ?? [],
            'table_schemas' => $this->tableSchemas,
            'statistics' => $this->statistics
        ];
    }
    
    protected function reset(): void
    {
        $this->tables = [];
        $this->wordpressData = [];
        $this->tableSchemas = [];
        $this->statistics = [];
    }
    
    protected function parseSqlFile(string $filePath): void
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open SQL file: {$filePath}");
        }
        
        $currentStatement = '';
        $currentTable = null;
        $insideInsert = false;
        $lineNumber = 0;
        
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                
                // Skip comments and empty lines
                if (empty($line) || $this->isComment($line)) {
                    continue;
                }
                
                $currentStatement .= $line . "\n";
                
                // Check if statement is complete (ends with semicolon not in quotes)
                if ($this->isStatementComplete($currentStatement)) {
                    $this->processStatement(trim($currentStatement), $lineNumber);
                    $currentStatement = '';
                }
            }
            
            // Process any remaining statement
            if (!empty(trim($currentStatement))) {
                $this->processStatement(trim($currentStatement), $lineNumber);
            }
            
        } finally {
            fclose($handle);
        }
    }
    
    protected function isComment(string $line): bool
    {
        return strpos($line, '--') === 0 || 
               strpos($line, '#') === 0 || 
               strpos($line, '/*') === 0;
    }
    
    protected function isStatementComplete(string $statement): bool
    {
        // Simple check for semicolon at end (could be improved for complex cases)
        return substr(trim($statement), -1) === ';';
    }
    
    protected function processStatement(string $statement, int $lineNumber): void
    {
        $statement = trim($statement, "; \t\n\r\0\x0B");
        
        // Detect statement type
        $upperStatement = strtoupper(substr($statement, 0, 20));
        
        if (strpos($upperStatement, 'CREATE TABLE') === 0) {
            $this->parseCreateTable($statement);
        } elseif (strpos($upperStatement, 'INSERT INTO') === 0) {
            $this->parseInsertStatement($statement, $lineNumber);
        }
        // We could add more statement types (ALTER, DROP, etc.) as needed
    }
    
    protected function parseCreateTable(string $statement): void
    {
        // Extract table name
        if (preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?([^`\s]+)`?\s*\(/i', $statement, $matches)) {
            $tableName = $matches[1];
            
            // Parse column definitions
            $columns = $this->parseTableColumns($statement);
            
            $this->tableSchemas[$tableName] = [
                'name' => $tableName,
                'columns' => $columns,
                'primary_key' => $this->extractPrimaryKey($statement),
                'indexes' => $this->extractIndexes($statement),
                'is_wordpress_table' => $this->isWordPressTable($tableName)
            ];
            
            // Initialize table data array
            $this->tables[$tableName] = [];
        }
    }
    
    protected function parseTableColumns(string $statement): array
    {
        $columns = [];
        
        // Extract everything between the parentheses
        if (preg_match('/\((.*)\)/s', $statement, $matches)) {
            $columnsSection = $matches[1];
            
            // Split by commas but respect parentheses (for ENUM, etc.)
            $parts = $this->splitRespectingParentheses($columnsSection, ',');
            
            foreach ($parts as $part) {
                $part = trim($part);
                
                // Skip constraints (PRIMARY KEY, FOREIGN KEY, etc.)
                if (preg_match('/^(PRIMARY|FOREIGN|UNIQUE|KEY|INDEX|CONSTRAINT)/i', $part)) {
                    continue;
                }
                
                // Parse column definition
                $column = $this->parseColumnDefinition($part);
                if ($column) {
                    $columns[$column['name']] = $column;
                }
            }
        }
        
        return $columns;
    }
    
    protected function parseColumnDefinition(string $definition): ?array
    {
        // Basic pattern: `column_name` TYPE [constraints]
        if (preg_match('/^`?([^`\s]+)`?\s+([^\s\(]+)(\([^\)]+\))?\s*(.*)/i', $definition, $matches)) {
            $columnName = $matches[1];
            $dataType = strtolower($matches[2]);
            $typeModifier = $matches[3] ?? '';
            $constraints = trim($matches[4] ?? '');
            
            return [
                'name' => $columnName,
                'type' => $dataType,
                'type_modifier' => $typeModifier,
                'nullable' => !preg_match('/NOT NULL/i', $constraints),
                'default' => $this->extractDefault($constraints),
                'auto_increment' => preg_match('/AUTO_INCREMENT/i', $constraints),
                'constraints' => $constraints
            ];
        }
        
        return null;
    }
    
    protected function extractDefault(string $constraints): ?string
    {
        if (preg_match('/DEFAULT\s+([^\s]+)/i', $constraints, $matches)) {
            $default = $matches[1];
            // Remove quotes if present
            return trim($default, "'\"");
        }
        return null;
    }
    
    protected function extractPrimaryKey(string $statement): ?array
    {
        if (preg_match('/PRIMARY KEY\s*\(\s*([^)]+)\s*\)/i', $statement, $matches)) {
            $keys = array_map(fn($key) => trim($key, '`'), explode(',', $matches[1]));
            return array_map('trim', $keys);
        }
        return null;
    }
    
    protected function extractIndexes(string $statement): array
    {
        $indexes = [];
        
        // Find all KEY definitions
        if (preg_match_all('/(?:UNIQUE\s+)?KEY\s+`?([^`\s]+)`?\s*\(\s*([^)]+)\s*\)/i', $statement, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $indexes[] = [
                    'name' => $match[1],
                    'columns' => array_map(fn($col) => trim($col, '`'), explode(',', $match[2])),
                    'unique' => stripos($match[0], 'UNIQUE') !== false
                ];
            }
        }
        
        return $indexes;
    }
    
    protected function parseInsertStatement(string $statement, int $lineNumber): void
    {
        // Extract table name
        if (preg_match('/INSERT INTO\s+`?([^`\s]+)`?\s*/i', $statement, $matches)) {
            $tableName = $matches[1];
            
            if (!isset($this->tables[$tableName])) {
                $this->tables[$tableName] = [];
            }
            
            try {
                $rows = $this->parseInsertValues($statement, $tableName);
                $this->tables[$tableName] = array_merge($this->tables[$tableName], $rows);
            } catch (\Exception $e) {
                // Log parsing error but continue
                error_log("Error parsing INSERT at line {$lineNumber}: " . $e->getMessage());
            }
        }
    }
    
    protected function parseInsertValues(string $statement, string $tableName): array
    {
        $rows = [];
        
        // Extract columns if specified
        $columns = null;
        if (preg_match('/INSERT INTO\s+`?[^`\s]+`?\s*\(\s*([^)]+)\s*\)\s*VALUES/i', $statement, $matches)) {
            $columns = array_map(fn($col) => trim($col, '`'), explode(',', $matches[1]));
        } else {
            // Use table schema columns if available
            if (isset($this->tableSchemas[$tableName])) {
                $columns = array_keys($this->tableSchemas[$tableName]['columns']);
            }
        }
        
        // Extract VALUES section
        if (preg_match('/VALUES\s*(.*)/is', $statement, $matches)) {
            $valuesSection = $matches[1];
            
            // Parse multiple value sets: (val1, val2), (val3, val4), ...
            $valueSets = $this->parseValueSets($valuesSection);
            
            foreach ($valueSets as $valueSet) {
                if ($columns && count($columns) === count($valueSet)) {
                    $row = array_combine($columns, $valueSet);
                    $rows[] = $row;
                } else {
                    // If no columns specified or count mismatch, use numeric keys
                    $rows[] = $valueSet;
                }
            }
        }
        
        return $rows;
    }
    
    protected function parseValueSets(string $valuesSection): array
    {
        $valueSets = [];
        $currentSet = [];
        $inQuotes = false;
        $quoteChar = null;
        $escapeNext = false;
        $parenLevel = 0;
        $currentValue = '';
        
        $chars = str_split($valuesSection);
        
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];
            
            if ($escapeNext) {
                $currentValue .= $char;
                $escapeNext = false;
                continue;
            }
            
            if ($char === '\\') {
                $escapeNext = true;
                $currentValue .= $char;
                continue;
            }
            
            if (!$inQuotes) {
                if ($char === '(' && $parenLevel === 0) {
                    $parenLevel = 1;
                    $currentSet = [];
                    continue;
                } elseif ($char === ')' && $parenLevel === 1) {
                    if (!empty($currentValue) || $currentValue === '0') {
                        $currentSet[] = $this->parseValue(trim($currentValue));
                    }
                    $valueSets[] = $currentSet;
                    $currentValue = '';
                    $parenLevel = 0;
                    continue;
                } elseif ($char === ',' && $parenLevel === 1) {
                    $currentSet[] = $this->parseValue(trim($currentValue));
                    $currentValue = '';
                    continue;
                } elseif (($char === '"' || $char === "'") && $parenLevel === 1) {
                    $inQuotes = true;
                    $quoteChar = $char;
                    continue;
                }
            } else {
                if ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = null;
                    continue;
                }
            }
            
            if ($parenLevel === 1) {
                $currentValue .= $char;
            }
        }
        
        return $valueSets;
    }
    
    protected function parseValue(string $value): mixed
    {
        $value = trim($value);
        
        // Handle NULL
        if (strtoupper($value) === 'NULL') {
            return null;
        }
        
        // Handle numbers
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        // Return as string (already unquoted)
        return $value;
    }
    
    protected function splitRespectingParentheses(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $parenLevel = 0;
        $inQuotes = false;
        $quoteChar = null;
        
        $chars = str_split($text);
        
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];
            
            if (!$inQuotes) {
                if ($char === '"' || $char === "'") {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === '(') {
                    $parenLevel++;
                } elseif ($char === ')') {
                    $parenLevel--;
                } elseif ($char === $delimiter && $parenLevel === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            } else {
                if ($char === $quoteChar && ($i === 0 || $chars[$i-1] !== '\\')) {
                    $inQuotes = false;
                    $quoteChar = null;
                }
            }
            
            $current .= $char;
        }
        
        if (!empty($current)) {
            $parts[] = $current;
        }
        
        return $parts;
    }
    
    protected function isWordPressTable(string $tableName): bool
    {
        // Common WordPress table suffixes
        $wpTables = [
            'posts', 'postmeta', 'comments', 'commentmeta', 'users', 'usermeta',
            'terms', 'term_taxonomy', 'term_relationships', 'options'
        ];
        
        foreach ($wpTables as $wpTable) {
            if (str_ends_with($tableName, $wpTable)) {
                // Extract prefix
                $prefix = substr($tableName, 0, -strlen($wpTable));
                $this->wordpressPrefix = $prefix;
                return true;
            }
        }
        
        return false;
    }
    
    protected function extractWordPressData(): void
    {
        $prefix = $this->wordpressPrefix;
        
        // Extract posts
        if (isset($this->tables[$prefix . 'posts'])) {
            $this->wordpressData['posts'] = $this->tables[$prefix . 'posts'];
        }
        
        // Extract postmeta
        if (isset($this->tables[$prefix . 'postmeta'])) {
            $this->wordpressData['postmeta'] = $this->tables[$prefix . 'postmeta'];
        }
        
        // Extract comments
        if (isset($this->tables[$prefix . 'comments'])) {
            $this->wordpressData['comments'] = $this->tables[$prefix . 'comments'];
        }
        
        // Extract users
        if (isset($this->tables[$prefix . 'users'])) {
            $this->wordpressData['users'] = $this->tables[$prefix . 'users'];
        }
        
        // Extract terms and taxonomy
        if (isset($this->tables[$prefix . 'terms'])) {
            $this->wordpressData['terms'] = $this->tables[$prefix . 'terms'];
        }
        
        if (isset($this->tables[$prefix . 'term_taxonomy'])) {
            $this->wordpressData['term_taxonomy'] = $this->tables[$prefix . 'term_taxonomy'];
        }
    }
    
    protected function calculateStatistics(): void
    {
        $this->statistics = [
            'total_tables_found' => count($this->tables),
            'wordpress_tables_found' => count(array_filter($this->tableSchemas, fn($schema) => $schema['is_wordpress_table'])),
            'wordpress_prefix' => $this->wordpressPrefix,
            'data_summary' => [],
            'table_schemas_count' => count($this->tableSchemas),
            'largest_table' => $this->findLargestTable(),
            'table_relationships' => $this->analyzeTableRelationships()
        ];
        
        // Data summary
        foreach ($this->wordpressData as $type => $data) {
            $this->statistics['data_summary'][$type] = count($data);
        }
    }
    
    protected function findLargestTable(): array
    {
        $largest = ['name' => null, 'rows' => 0];
        
        foreach ($this->tables as $tableName => $rows) {
            if (count($rows) > $largest['rows']) {
                $largest = ['name' => $tableName, 'rows' => count($rows)];
            }
        }
        
        return $largest;
    }
    
    protected function analyzeTableRelationships(): array
    {
        $relationships = [];
        
        foreach ($this->tableSchemas as $tableName => $schema) {
            foreach ($schema['columns'] as $column) {
                // Look for foreign key patterns (column names ending with _id)
                if (str_ends_with($column['name'], '_id') && $column['name'] !== 'ID') {
                    $relationships[] = [
                        'table' => $tableName,
                        'column' => $column['name'],
                        'likely_references' => $this->guessForeignKeyTarget($column['name'])
                    ];
                }
            }
        }
        
        return $relationships;
    }
    
    protected function guessForeignKeyTarget(string $columnName): ?string
    {
        // Remove _id suffix to get likely table name
        $baseTable = substr($columnName, 0, -3);
        
        // Check if table exists with WordPress prefix
        $possibleTable = $this->wordpressPrefix . $baseTable . 's';
        if (isset($this->tableSchemas[$possibleTable])) {
            return $possibleTable;
        }
        
        // Try without 's'
        $possibleTable = $this->wordpressPrefix . $baseTable;
        if (isset($this->tableSchemas[$possibleTable])) {
            return $possibleTable;
        }
        
        return null;
    }
    
    public function getParsingReport(): array
    {
        return [
            'summary' => [
                'tables_parsed' => count($this->tables),
                'wordpress_tables' => count(array_filter($this->tableSchemas, fn($s) => $s['is_wordpress_table'])),
                'total_rows' => array_sum(array_map('count', $this->tables)),
                'wordpress_prefix' => $this->wordpressPrefix
            ],
            'wordpress_data_found' => array_keys($this->wordpressData),
            'table_schemas' => $this->tableSchemas,
            'statistics' => $this->statistics
        ];
    }
    
    public function getTableSchema(string $tableName): ?array
    {
        return $this->tableSchemas[$tableName] ?? null;
    }
    
    public function getTableData(string $tableName): array
    {
        return $this->tables[$tableName] ?? [];
    }
    
    public function getAllTableNames(): array
    {
        return array_keys($this->tables);
    }
    
    public function getWordPressTableNames(): array
    {
        return array_keys(array_filter($this->tableSchemas, fn($schema) => $schema['is_wordpress_table']));
    }
}