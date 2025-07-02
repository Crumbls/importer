<?php

namespace Crumbls\Importer\Support;

class DelimiterDetector
{
    /**
     * Detect the most likely delimiter in a CSV file
     */
    public static function detect(string $filePath, array $possibleDelimiters = [',', ';', "\t", '|', ':']): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ','; // Default fallback
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ','; // Default fallback
        }
        
        // Read a sample of lines for analysis
        $sampleLines = [];
        $maxLines = 10;
        $lineCount = 0;
        
        while (($line = fgets($handle)) !== false && $lineCount < $maxLines) {
            $sampleLines[] = trim($line);
            $lineCount++;
        }
        
        fclose($handle);
        
        if (empty($sampleLines)) {
            return ','; // Default fallback
        }
        
        return self::detectFromLines($sampleLines, $possibleDelimiters);
    }
    
    /**
     * Detect delimiter from an array of lines
     */
    public static function detectFromLines(array $lines, array $possibleDelimiters = [',', ';', "\t", '|', ':']): string
    {
        if (empty($lines)) {
            return ','; // Default fallback
        }
        
        $delimiterScores = [];
        
        foreach ($possibleDelimiters as $delimiter) {
            $delimiterScores[$delimiter] = self::scoreDelimiter($lines, $delimiter);
        }
        
        // Find delimiter with highest score
        $bestDelimiter = array_keys($delimiterScores, max($delimiterScores))[0];
        
        // If all scores are very low, default to comma
        if ($delimiterScores[$bestDelimiter] < 2) {
            return ',';
        }
        
        return $bestDelimiter;
    }
    
    /**
     * Score a delimiter based on consistency across lines
     */
    protected static function scoreDelimiter(array $lines, string $delimiter): int
    {
        $counts = [];
        $validLines = 0;
        
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue; // Skip empty lines
            }
            
            $count = substr_count($line, $delimiter);
            
            // Skip lines with no delimiters (might be headers or comments)
            if ($count === 0) {
                continue;
            }
            
            $counts[] = $count;
            $validLines++;
        }
        
        if ($validLines === 0) {
            return 0;
        }
        
        // Score based on consistency
        $uniqueCounts = array_unique($counts);
        
        if (count($uniqueCounts) === 1) {
            // Perfect consistency - highest score
            return max($counts) * 10;
        }
        
        // Calculate variance penalty
        $mean = array_sum($counts) / count($counts);
        $variance = 0;
        
        foreach ($counts as $count) {
            $variance += pow($count - $mean, 2);
        }
        
        $variance = $variance / count($counts);
        
        // Lower variance = higher score
        $consistencyScore = max(1, (int) ($mean * 5 - $variance));
        
        return max(0, $consistencyScore);
    }
    
    /**
     * Detect delimiter from string content
     */
    public static function detectFromString(string $content, array $possibleDelimiters = [',', ';', "\t", '|', ':']): string
    {
        $lines = explode("\n", $content);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines); // Remove empty lines
        
        return self::detectFromLines($lines, $possibleDelimiters);
    }
    
    /**
     * Get the standard set of delimiters to check
     */
    public static function getStandardDelimiters(): array
    {
        return [',', ';', "\t", '|', ':'];
    }
    
    /**
     * Get delimiter name for display purposes
     */
    public static function getDelimiterName(string $delimiter): string
    {
        $names = [
            ',' => 'comma',
            ';' => 'semicolon',
            "\t" => 'tab',
            '|' => 'pipe',
            ':' => 'colon',
        ];
        
        return $names[$delimiter] ?? 'unknown';
    }
}