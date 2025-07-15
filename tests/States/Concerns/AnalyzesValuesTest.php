<?php

use Crumbls\Importer\States\Concerns\AnalyzesValues;
use Illuminate\Support\Collection;

// Create a test class that uses the trait
class AnalyzesValuesTestClass
{
    use AnalyzesValues;
}

beforeEach(function () {
    $this->analyzer = new AnalyzesValuesTestClass();
});

describe('analyzeValues', function () {
    it('handles empty collections', function () {
        $result = $this->analyzer->analyzeValues(collect([]));
        
        expect($result['type'])->toBe('empty');
        expect($result['confidence'])->toBe(100);
        expect($result['breakdown']['total_count'])->toBe(0);
        expect($result['sample_values'])->toBeEmpty();
        expect($result['recommendations']['primary_type'])->toBe('text');
    });

    it('analyzes string data correctly', function () {
        $values = collect(['hello', 'world', 'test', 'string']);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('string');
        expect($result['confidence'])->toBeGreaterThan(0);
        expect($result['breakdown']['total_count'])->toBe(4);
        expect($result['breakdown']['unique_count'])->toBe(4);
        expect($result['breakdown']['uniqueness_ratio'])->toBe(100.0);
        expect($result['sample_values'])->toHaveCount(4);
    });

    it('analyzes integer data correctly', function () {
        $values = collect([1, 2, 3, 4, 5]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('integer');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['numeric_analysis']['is_likely_integer'])->toBe(true);
        expect($result['breakdown']['numeric_analysis']['min'])->toBe(1);
        expect($result['breakdown']['numeric_analysis']['max'])->toBe(5);
        expect($result['breakdown']['numeric_analysis']['average'])->toBe(3);
    });

    it('analyzes float data correctly', function () {
        $values = collect([1.5, 2.7, 3.14, 4.0]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('float');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['numeric_analysis']['is_likely_float'])->toBe(true);
        expect($result['breakdown']['numeric_analysis']['min'])->toBe(1.5);
        expect($result['breakdown']['numeric_analysis']['max'])->toBe(4.0);
    });

    it('analyzes boolean data correctly', function () {
        $values = collect([true, false, true, true, false]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('boolean');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['boolean_analysis']['true_count'])->toBe(3);
        expect($result['breakdown']['boolean_analysis']['false_count'])->toBe(2);
        expect($result['breakdown']['boolean_analysis']['is_likely_boolean'])->toBe(true);
    });

    it('analyzes boolean strings correctly', function () {
        $values = collect(['true', 'false', 'yes', 'no', '1', '0']);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('boolean');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['boolean_analysis']['is_likely_boolean'])->toBe(true);
    });

    it('analyzes datetime data correctly', function () {
        $values = collect([
            '2023-01-01 12:00:00',
            '2023-01-02 13:30:00',
            '2023-01-03 14:45:00'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('datetime');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['datetime_analysis']['is_likely_datetime'])->toBe(true);
        expect($result['breakdown']['datetime_analysis']['formats'])->toHaveKey('Y-m-d H:i:s');
    });

    it('analyzes various datetime formats', function () {
        $values = collect([
            '2023-01-01',
            '2023/01/02',
            '01/03/2023',
            '2023-01-04T10:30:00Z'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('datetime');
        expect($result['breakdown']['datetime_analysis']['formats'])->toBeArray();
        expect(count($result['breakdown']['datetime_analysis']['formats']))->toBeGreaterThan(1);
    });

    it('analyzes JSON data correctly', function () {
        $values = collect([
            '{"name": "John", "age": 30}',
            '{"name": "Jane", "age": 25}',
            '[1, 2, 3, 4]'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('json');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['json_analysis']['is_likely_json'])->toBe(true);
        expect($result['breakdown']['json_analysis']['json_count'])->toBe(3);
    });

    it('analyzes URL data correctly', function () {
        $values = collect([
            'https://example.com',
            'http://test.org',
            'https://www.google.com/search?q=test'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('url');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['url_analysis']['is_likely_url'])->toBe(true);
        expect($result['breakdown']['url_analysis']['url_count'])->toBe(3);
    });

    it('analyzes email data correctly', function () {
        $values = collect([
            'john@example.com',
            'jane.doe@test.org',
            'admin@company.co.uk'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('email');
        expect($result['confidence'])->toBeGreaterThan(80);
        expect($result['breakdown']['email_analysis']['is_likely_email'])->toBe(true);
        expect($result['breakdown']['email_analysis']['email_count'])->toBe(3);
    });

    it('handles mixed data types', function () {
        $values = collect([
            'hello',
            123,
            true,
            '2023-01-01',
            'world'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBeString();
        expect($result['confidence'])->toBeLessThan(100);
        expect($result['breakdown']['type_breakdown'])->toBeArray();
        expect(count($result['breakdown']['type_breakdown']))->toBeGreaterThan(1);
    });

    it('handles null and empty values', function () {
        $values = collect([
            'hello',
            null,
            '',
            'world',
            0
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['total_count'])->toBe(5);
        expect($result['breakdown']['null_count'])->toBe(1);
        expect($result['breakdown']['empty_count'])->toBe(2); // Empty string and 0 are both counted as empty
        expect($result['breakdown']['unique_count'])->toBe(2); // Only 'hello' and 'world' are non-empty
    });

    it('calculates uniqueness ratio correctly', function () {
        $values = collect([
            'apple', 'apple', 'apple',
            'banana', 'banana',
            'cherry'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['unique_count'])->toBe(3);
        expect($result['breakdown']['uniqueness_ratio'])->toBe(50.0); // 3 unique out of 6 total
    });

    it('provides recommendations for high uniqueness', function () {
        $values = collect([
            'unique1', 'unique2', 'unique3', 'unique4', 'unique5',
            'unique6', 'unique7', 'unique8', 'unique9', 'unique10'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['uniqueness_ratio'])->toBe(100.0);
        expect($result['recommendations']['notes'])->toContain('High uniqueness suggests this might be a unique identifier');
    });

    it('provides recommendations for low uniqueness', function () {
        // Create data with very low uniqueness (1 unique out of 21 = ~4.8%)
        $values = collect(array_fill(0, 20, 'category1'));
        $values->push('category2');
        
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['uniqueness_ratio'])->toBe(9.52); // 2 unique out of 21 total
        expect($result['recommendations']['notes'])->toContain('Low uniqueness suggests this might be a category or enum field');
    });

    it('handles numeric strings correctly', function () {
        $values = collect(['123', '456', '789']);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('integer');
        expect($result['breakdown']['numeric_analysis']['is_likely_numeric'])->toBe(true);
        expect($result['breakdown']['type_breakdown'])->toHaveKey('numeric_string');
    });

    it('handles long text correctly', function () {
        $longText = str_repeat('This is a very long text that exceeds 255 characters. ', 10);
        $values = collect([$longText, $longText . ' More text.']);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['type_breakdown'])->toHaveKey('long_text');
    });

    it('provides fallback recommendations when confidence is low', function () {
        $values = collect([
            'mixed', 123, true, 'data'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['confidence'])->toBeLessThan(95);
        expect($result['recommendations']['alternatives'])->toContain('text');
    });

    it('handles edge case with only null values', function () {
        $values = collect([null, null, null]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['total_count'])->toBe(3);
        expect($result['breakdown']['null_count'])->toBe(3);
        expect($result['breakdown']['unique_count'])->toBe(0);
    });

    it('handles single value correctly', function () {
        $values = collect(['single_value']);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['total_count'])->toBe(1);
        expect($result['breakdown']['unique_count'])->toBe(1);
        expect($result['breakdown']['uniqueness_ratio'])->toBe(100.0);
        expect($result['sample_values'])->toHaveCount(1);
    });

    it('limits sample values to 10', function () {
        $values = collect(range(1, 50));
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['sample_values'])->toHaveCount(10);
    });

    it('identifies potential ID fields correctly', function () {
        $values = collect([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['breakdown']['uniqueness_ratio'])->toBe(100.0);
        expect($result['recommendations']['notes'])->toContain('High uniqueness suggests this might be an ID field');
    });

    it('handles timezone information in datetime recommendations', function () {
        $values = collect([
            '2023-01-01T12:00:00Z',
            '2023-01-02T13:30:00+01:00'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('datetime');
        expect($result['recommendations']['notes'])->toContain('Consider timezone handling for datetime values');
    });

    it('recommends JSON parsing for JSON fields', function () {
        $values = collect([
            '{"nested": {"data": "value"}}',
            '{"more": "complex", "json": [1, 2, 3]}'
        ]);
        $result = $this->analyzer->analyzeValues($values);
        
        expect($result['type'])->toBe('json');
        expect($result['recommendations']['notes'])->toContain('Consider parsing JSON for more detailed field mapping');
    });
});