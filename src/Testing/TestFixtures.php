<?php

namespace Crumbls\Importer\Testing;

class TestFixtures
{
    public static function basicCsvData(): array
    {
        return [
            ['id', 'name', 'email', 'age'],
            ['1', 'John Doe', 'john@example.com', '30'],
            ['2', 'Jane Smith', 'jane@example.com', '25'],
            ['3', 'Bob Johnson', 'bob@example.com', '35']
        ];
    }
    
    public static function csvWithHeaders(): array
    {
        return [
            'headers' => ['id', 'name', 'email', 'age', 'status'],
            'data' => [
                ['1', 'John Doe', 'john@example.com', '30', 'active'],
                ['2', 'Jane Smith', 'jane@example.com', '25', 'inactive'],
                ['3', 'Bob Johnson', 'bob@example.com', '35', 'active'],
                ['4', 'Alice Brown', 'alice@example.com', '28', 'active']
            ]
        ];
    }
    
    public static function csvWithValidationIssues(): array
    {
        return [
            'headers' => ['id', 'name', 'email', 'age'],
            'data' => [
                ['1', 'John Doe', 'john@example.com', '30'],        // Valid
                ['2', '', 'jane@example.com', '25'],                // Missing name
                ['3', 'Bob Johnson', 'invalid-email', '35'],        // Invalid email
                ['4', 'Alice Brown', 'alice@example.com', 'abc'],   // Invalid age
                ['5', 'Charlie Wilson', 'charlie@example.com', ''],  // Missing age
                ['6', 'David Lee', 'david@example.com', '40']       // Valid
            ]
        ];
    }
    
    public static function csvWithSpecialCharacters(): array
    {
        return [
            'headers' => ['id', 'name', 'description', 'price'],
            'data' => [
                ['1', 'Product "A"', 'A great product, with commas!', '19.99'],
                ['2', 'Product\\B', 'Description with\\nline breaks', '29.99'],
                ['3', 'Product;C', 'Semi;colon;separated', '39.99'],
                ['4', 'Product	D', 'Tab	separated	description', '49.99']
            ]
        ];
    }
    
    public static function csvWithDifferentDelimiters(): array
    {
        return [
            'comma' => [
                'delimiter' => ',',
                'content' => "id,name,email\n1,John,john@example.com\n2,Jane,jane@example.com"
            ],
            'semicolon' => [
                'delimiter' => ';',
                'content' => "id;name;email\n1;John;john@example.com\n2;Jane;jane@example.com"
            ],
            'tab' => [
                'delimiter' => "\t",
                'content' => "id\tname\temail\n1\tJohn\tjohn@example.com\n2\tJane\tjane@example.com"
            ],
            'pipe' => [
                'delimiter' => '|',
                'content' => "id|name|email\n1|John|john@example.com\n2|Jane|jane@example.com"
            ]
        ];
    }
    
    public static function csvWithInconsistentColumns(): array
    {
        return [
            'headers' => ['id', 'name', 'email'],
            'data' => [
                ['1', 'John Doe', 'john@example.com'],                    // 3 columns (correct)
                ['2', 'Jane Smith'],                                      // 2 columns (missing email)
                ['3', 'Bob Johnson', 'bob@example.com', 'extra', 'data'], // 5 columns (extra data)
                ['4', 'Alice Brown', 'alice@example.com']                 // 3 columns (correct)
            ]
        ];
    }
    
    public static function largeCsvStructure(int $rows = 1000): array
    {
        $headers = ['id', 'first_name', 'last_name', 'email', 'department', 'salary', 'hire_date', 'status'];
        $data = [];
        
        $departments = ['Engineering', 'Marketing', 'Sales', 'HR', 'Finance'];
        $statuses = ['active', 'inactive', 'pending'];
        
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = [
                (string) $i,
                "FirstName{$i}",
                "LastName{$i}",
                "user{$i}@company.com",
                $departments[($i - 1) % count($departments)],
                (string) (50000 + ($i * 1000)),
                date('Y-m-d', strtotime("-{$i} days")),
                $statuses[($i - 1) % count($statuses)]
            ];
        }
        
        return [
            'headers' => $headers,
            'data' => $data
        ];
    }
    
    public static function csvValidationRules(): array
    {
        return [
            'basic' => [
                'id' => ['required' => true, 'numeric' => true],
                'name' => ['required' => true, 'min_length' => 2],
                'email' => ['required' => true, 'email' => true],
                'age' => ['numeric' => true]
            ],
            'strict' => [
                'id' => ['required' => true, 'integer' => true],
                'name' => ['required' => true, 'min_length' => 2, 'max_length' => 50],
                'email' => ['required' => true, 'email' => true],
                'age' => ['required' => true, 'integer' => true],
                'status' => ['required' => true, 'in' => ['active', 'inactive']]
            ],
            'lenient' => [
                'name' => ['min_length' => 1],
                'email' => ['email' => true]
            ]
        ];
    }
    
    public static function createTempFile(string $content, string $extension = '.csv'): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'importer_test_') . $extension;
        file_put_contents($tempFile, $content);
        return $tempFile;
    }
    
    public static function createCsvFile(array $data, string $delimiter = ',', bool $includeHeaders = true): string
    {
        $content = '';
        
        if ($includeHeaders && isset($data['headers'])) {
            $content .= implode($delimiter, $data['headers']) . "\n";
            $rows = $data['data'] ?? [];
        } else {
            $rows = $data;
        }
        
        foreach ($rows as $row) {
            $escapedRow = array_map(function($field) use ($delimiter) {
                if (strpos($field, $delimiter) !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . str_replace('"', '""', $field) . '"';
                }
                return $field;
            }, $row);
            
            $content .= implode($delimiter, $escapedRow) . "\n";
        }
        
        return self::createTempFile($content);
    }
    
    public static function cleanupTempFile(string $filepath): void
    {
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }
}