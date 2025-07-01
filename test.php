<?php

// Simple test file to verify the structure works
require_once __DIR__ . '/../vendor/autoload.php';

use Crumbls\Importer\Drivers\CsvDriver;

// Create a test CSV file
$testCsv = __DIR__ . '/test.csv';
file_put_contents($testCsv, "name,email,age\nJohn,john@test.com,25\nJane,jane@test.com,30");

// Test the driver
$driver = new CsvDriver(['delimiter' => ',']);

echo "Testing CSV Driver...\n";

// First run
echo "First run:\n";
$result1 = $driver->withTempStorage()->import($testCsv);
echo "Success: " . ($result1->success ? 'Yes' : 'No') . "\n";
echo "Resumed: " . ($result1->meta['resumed'] ?? false ? 'Yes' : 'No') . "\n";
echo "State Hash: " . ($result1->meta['state_hash'] ?? 'None') . "\n\n";

// Second run (should resume)
echo "Second run (should resume):\n";
$result2 = $driver->withTempStorage()->import($testCsv);
echo "Success: " . ($result2->success ? 'Yes' : 'No') . "\n";
echo "Resumed: " . ($result2->meta['resumed'] ?? false ? 'Yes' : 'No') . "\n";
echo "State Hash: " . ($result2->meta['state_hash'] ?? 'None') . "\n";

// Cleanup
unlink($testCsv);
echo "\nTest completed!\n";
