<?php

namespace Crumbls\Importer\Console\Prompts\CsvDriver;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\Support\SourceResolverManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class ConfigureHeadersPrompt extends AbstractPrompt
{

    public function render(): bool
    {
		$sm = $this->record->getStateMachine();
		$state = $sm->getCurrentState();

		if (!$state->needsConfiguration()) {
			return true;
		}

        info('Analyzing CSV file structure...');
        
        // Get the first few rows to show user
        $rows = $this->getPreviewRows();

        if (!$rows) {
            $this->command->error('Unable to read CSV file');
            return false;
        }

        // Step 1: Show data preview FIRST
        $this->showDataPreview($rows);
        
        $firstRow = $rows[0] ?? [];
        $secondRow = $rows[1] ?? [];
        
        // Step 2: Ask user about headers with context
        $firstRowHeader = $this->askAboutHeaders($firstRow, $secondRow);

        // Step 3: Initialize headers based on user choice
        if ($firstRowHeader) {
            $headers = array_map([$this, 'sanitizeColumnName'], $firstRow);
        } else {
            // Generate default column names
            $headers = [];
            for ($i = 0; $i < count($firstRow); $i++) {
                $headers[] = 'column_' . ($i + 1);
            }
        }
        
        // Step 4: Show headers and allow editing
        $headers = $this->configureHeaders($rows, $headers, $firstRowHeader);
        
        if ($headers === null) {
            return false; // User cancelled
        }

        // Step 5: Final confirmation
        $this->showFinalPreview($rows, $headers, $firstRowHeader);
        
        if (!confirm('Save these column settings?', true)) {
            return false;
        }

        // Save configuration
        $metadata = $this->record->metadata ?? [];
        $metadata['headers'] = $headers;
        $metadata['headers_first_row'] = $firstRowHeader;
        $this->record->update(['metadata' => $metadata]);
		return true;
    }

    protected function showDataPreview(array $rows): void
    {
        info('Data Preview');
        note('Here\'s what your CSV file looks like:');
        
        // Prepare table data - show up to 4 rows and limit to 6 columns for readability
        $tableHeaders = [];
        $maxCols = min(6, count($rows[0] ?? []));
        
        for ($i = 1; $i <= $maxCols; $i++) {
            $tableHeaders[] = "Col {$i}";
        }
        
        if (count($rows[0] ?? []) > 6) {
            $tableHeaders[] = '...more';
        }
        
        $tableData = [];
        for ($rowIndex = 0; $rowIndex < min(4, count($rows)); $rowIndex++) {
            $row = $rows[$rowIndex];
            $displayRow = [];
            
            for ($colIndex = 0; $colIndex < $maxCols; $colIndex++) {
                $value = $row[$colIndex] ?? '';
                // Truncate long values
                $displayRow[] = strlen($value) > 25 ? substr($value, 0, 22) . '...' : $value;
            }
            
            if (count($row) > 6) {
                $displayRow[] = '...';
            }
            
            $tableData[] = $displayRow;
        }
        
        table($tableHeaders, $tableData);
        
        $totalCols = count($rows[0] ?? []);
        $totalRows = count($rows);
        note("File contains {$totalCols} columns and at least {$totalRows} rows");
    }

    protected function configureHeaders(array $rows, array $headers, bool $firstRowHeader): ?array
    {
        while (true) {
            // Show current header configuration
            $this->showCurrentHeaders($headers, $rows, $firstRowHeader);
            
            $action = select(
                'What would you like to do?',
                [
                    'continue' => 'Continue with these headers',
                    'edit' => 'Edit a column name',
                    'auto' => 'Auto-generate names from data',
                    'cancel' => 'Cancel import'
                ],
                default: 'continue'
            );
            
            switch ($action) {
                case 'continue':
                    return $headers;
                    
                case 'edit':
                    $headers = $this->editSingleHeader($headers);
                    break;
                    
                case 'auto':
                    $headers = $this->autoGenerateHeaders($rows, $firstRowHeader);
                    break;
                    
                case 'cancel':
                    return null;
            }
        }
    }

    protected function showCurrentHeaders(array $headers, array $rows, bool $firstRowHeader): void
    {
        info('Current Column Configuration');
        
        $tableHeaders = ['#', 'Column Name', 'Sample Data'];
        $tableData = [];
        
        $dataStartRow = $firstRowHeader ? 1 : 0;
        
        foreach ($headers as $index => $header) {
            $sampleData = $rows[$dataStartRow][$index] ?? '';
            $sampleData = strlen($sampleData) > 30 ? substr($sampleData, 0, 27) . '...' : $sampleData;
            
            $tableData[] = [
                $index + 1,
                $header ?: '<empty>',
                $sampleData
            ];
        }
        
        table($tableHeaders, $tableData);
    }

    protected function editSingleHeader(array $headers): array
    {
        // Show numbered list for user to choose from
        $choices = [];
        foreach ($headers as $index => $header) {
            $choices[$index] = ($header ?: '<empty>');
        }
		$choices = array_combine(
			array_map(function($choice) { return 'idx_'.$choice; },
			range(0, count($choices)-1)
			), $choices);

//		$choices = array_combine($headers, $choices);

        $selectedIndex = select(
            'Which column would you like to edit?',
            $choices
        );

		$currentName = $choices[$selectedIndex];

        $newName = text(
            "Enter new name for column " . (substr($selectedIndex, 4)),
            default: $currentName,
            placeholder: 'e.g., customer_name, email_address, etc.'
        );

	    $choices[$selectedIndex] = $this->sanitizeColumnName($newName ?: $currentName);

		return array_values($choices);
    }

    protected function autoGenerateHeaders(array $rows, bool $firstRowHeader): array
    {
        $dataStartRow = $firstRowHeader ? 1 : 0;
        $headers = [];
        
        foreach ($rows[0] as $index => $value) {
            // Try to generate meaningful names from sample data
            $sampleValue = $rows[$dataStartRow][$index] ?? '';
            
            if (filter_var($sampleValue, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'email';
            } elseif (is_numeric($sampleValue) && $sampleValue > 1000000000) {
                $headers[] = 'timestamp';
            } elseif (is_numeric($sampleValue) && strpos($sampleValue, '.') !== false) {
                $headers[] = 'amount';
            } elseif (is_numeric($sampleValue)) {
                $headers[] = 'number_' . ($index + 1);
            } elseif (strlen($sampleValue) > 50) {
                $headers[] = 'description';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $sampleValue)) {
                $headers[] = 'date';
            } else {
                $headers[] = 'column_' . ($index + 1);
            }
        }
        
        return $headers;
    }

    protected function showFinalPreview(array $rows, array $headers, bool $firstRowHeader): void
    {
        info('Final Preview');
        note('Here\'s how your data will be imported:');
        
        // Show headers
        $dataStartRow = $firstRowHeader ? 1 : 0;
        $previewData = [];
        
        // Add header row
        $previewData[] = $headers;
        
        // Add sample data rows
        for ($i = $dataStartRow; $i < min($dataStartRow + 3, count($rows)); $i++) {
            if (isset($rows[$i])) {
                $row = [];
                foreach ($headers as $colIndex => $header) {
                    $value = $rows[$i][$colIndex] ?? '';
                    $row[] = strlen($value) > 20 ? substr($value, 0, 17) . '...' : $value;
                }
                $previewData[] = $row;
            }
        }
        
        // Use array_shift to get headers separately for the table function
        $tableHeaders = array_shift($previewData);
        
        table($tableHeaders, $previewData);
        
        if ($firstRowHeader) {
            note('First row will be treated as headers and skipped during import');
        } else {
            note('All rows will be imported as data');
        }
    }

    protected function sanitizeColumnName(string $name): string
    {
        if (empty($name)) {
            return '';
        }
        
        // Convert to snake_case and remove invalid characters
        $name = Str::snake(trim($name));
        
        // Remove any characters that aren't letters, numbers, or underscores
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        
        // Ensure it doesn't start with a number
        if (preg_match('/^[0-9]/', $name)) {
            $name = 'col_' . $name;
        }
        
        // Ensure it's not empty after sanitization
        if (empty($name)) {
            $name = 'column';
        }
        
        return $name;
    }

    protected function getPreviewRows(): ?array
    {
        try {
            $sourceResolver = new SourceResolverManager();
            
            if ($this->record->source_type == 'storage') {
                $sourceResolver->addResolver(new FileSourceResolver(
                    $this->record->source_type,
                    $this->record->source_detail
                ));
            } else {
                throw new \Exception("Unsupported source type: {$this->record->source_type}");
            }

            $sourcePath = $sourceResolver->resolve($this->record->source_type, $this->record->source_detail);
            if (!$sourcePath) {
                return null;
            }

            $handle = fopen($sourcePath, 'r');
            if (!$handle) {
                return null;
            }

            $rows = [];
            for ($i = 0; $i < 3 && ($row = fgetcsv($handle)); $i++) {
                $rows[] = $row;
            }
            fclose($handle);

            return $rows;
        } catch (\Exception $e) {
            return null;
        }
    }

	protected function askAboutHeaders(array $firstRow, array $secondRow): bool
    {
	    $detectedAsHeaders = $this->isProbablyHeaderRow($firstRow, $secondRow);

        info('Header Detection');
        
        if ($detectedAsHeaders) {
            note("Analysis suggests the first row contains column headers.");
        } else {
            note("Analysis suggests the first row contains data (not headers).");
        }

        return confirm(
            'Does the first row contain column headers?',
            $detectedAsHeaders
        );
    }

    protected function isProbablyHeaderRow(array $row1, array $row2): bool
    {
        if (count($row1) !== count($row2)) {
            // Uneven rows, likely a header
            return true;
        }

        $score = 0;
        $total = count($row1);

        $commonHeaderKeywords = [
            'id', 'name', 'email', 'date', 'phone', 'address', 'amount', 'price', 
            'title', 'description', 'status', 'created', 'updated', 'user', 'customer'
        ];

        for ($i = 0; $i < $total; $i++) {
            $val1 = trim($row1[$i]);
            $val2 = trim($row2[$i]);

            // Type difference: string vs numeric
            if (is_numeric($val2) && !is_numeric($val1)) {
                $score += 1;
            }

            // Check for header-like keywords
            $val1Lower = strtolower($val1);
            foreach ($commonHeaderKeywords as $keyword) {
                if (strpos($val1Lower, $keyword) !== false) {
                    $score += 1;
                    break;
                }
            }

            // Header values are often lowercase or snake_case
            if (preg_match('/^[a-z0-9_\s]+$/i', $val1)) {
                $score += 0.5;
            }

            // Avoid values with long content
            if (str_word_count($val1) <= 3 && strlen($val1) < 30) {
                $score += 0.25;
            }
        }

        // Uniqueness bonus
        if (count(array_unique($row1)) === $total) {
            $score += 1;
        }

        // Normalize and return true if more than half the columns are likely header
        return $score >= ($total / 2);
    }

}