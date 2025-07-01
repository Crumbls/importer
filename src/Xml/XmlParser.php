<?php

namespace Crumbls\Importer\Xml;

class XmlParser
{
    protected \SimpleXMLElement $xml;
    protected array $namespaces = [];
    protected array $fieldMappings = [];
    
    public function __construct(\SimpleXMLElement $xml)
    {
        $this->xml = $xml;
    }
    
    public static function fromFile(string $filePath): self
    {
        $xml = simplexml_load_file($filePath);
        if (!$xml) {
            throw new \RuntimeException("Failed to parse XML file: {$filePath}");
        }
        
        return new self($xml);
    }
    
    public static function fromString(string $content): self
    {
        $xml = simplexml_load_string($content);
        if (!$xml) {
            throw new \RuntimeException("Failed to parse XML content");
        }
        
        return new self($xml);
    }
    
    public function registerNamespace(string $prefix, string $uri): self
    {
        $this->namespaces[$prefix] = $uri;
        $this->xml->registerXPathNamespace($prefix, $uri);
        return $this;
    }
    
    public function registerNamespaces(array $namespaces): self
    {
        foreach ($namespaces as $prefix => $uri) {
            $this->registerNamespace($prefix, $uri);
        }
        return $this;
    }
    
    public function getRegisteredNamespaces(): array
    {
        return $this->namespaces;
    }
    
    public function xpath(string $expression): array
    {
        $result = $this->xml->xpath($expression);
        return $result !== false ? $result : [];
    }
    
    public function extractRecords(string $recordXpath, array $fieldMappings): \Generator
    {
        $records = $this->xpath($recordXpath);
        
        foreach ($records as $record) {
            $extractedData = [];
            
            foreach ($fieldMappings as $fieldName => $xpath) {
                // Handle null xpath values (auto-generated fields)
                if ($xpath === null) {
                    $extractedData[$fieldName] = null;
                } else {
                    $extractedData[$fieldName] = $this->extractField($record, $xpath);
                }
            }
            
            yield $extractedData;
        }
    }
    
    public function extractUniqueValues(string $xpath, ?string $keyField = null): array
    {
        $elements = $this->xpath($xpath);
        $unique = [];
        
        foreach ($elements as $element) {
            $value = (string) $element;
            if (!empty($value)) {
                if ($keyField) {
                    $unique[$value] = [$keyField => $value];
                } else {
                    $unique[] = $value;
                }
            }
        }
        
        return array_values($unique);
    }
    
    public function extractNestedRecords(string $parentXpath, string $childXpath, array $fieldMappings): \Generator
    {
        $parents = $this->xpath($parentXpath);
        
        foreach ($parents as $parent) {
            // Get children relative to this parent
            $children = $parent->xpath($childXpath);
            if (!$children) continue;
            
            foreach ($children as $child) {
                $extractedData = [];
                
                foreach ($fieldMappings as $fieldName => $xpath) {
                    $extractedData[$fieldName] = $this->extractField($child, $xpath);
                }
                
                yield $extractedData;
            }
        }
    }
    
    public function extractField(\SimpleXMLElement $element, string $xpath): string
    {
        // Handle different xpath patterns
        if ($xpath === 'text()' || $xpath === '.') {
            return (string) $element;
        }
        
        if (str_starts_with($xpath, '@')) {
            // Attribute extraction: @attribute
            $attrName = substr($xpath, 1);
            return (string) $element[$attrName];
        }
        
        if (str_contains($xpath, '/')) {
            // Relative xpath: ./child, child/subchild, ../sibling
            $result = $element->xpath($xpath);
            return !empty($result) ? (string) $result[0] : '';
        }
        
        // Handle namespaced elements
        if (str_contains($xpath, ':')) {
            $result = $element->xpath($xpath);
            return !empty($result) ? (string) $result[0] : '';
        }
        
        // Direct child element
        return (string) $element->$xpath;
    }
    
    public function getDocumentInfo(): array
    {
        return [
            'root_element' => $this->xml->getName(),
            'namespaces' => $this->xml->getDocNamespaces(true),
            'registered_namespaces' => $this->namespaces
        ];
    }
    
    public function validateStructure(array $requiredXpaths): array
    {
        $validation = [];
        
        foreach ($requiredXpaths as $name => $xpath) {
            $result = $this->xpath($xpath);
            $validation[$name] = [
                'xpath' => $xpath,
                'found' => !empty($result),
                'count' => count($result)
            ];
        }
        
        return $validation;
    }
    
    public function preview(string $recordXpath, array $fieldMappings, int $limit = 5): array
    {
        $preview = [];
        $count = 0;
        
        foreach ($this->extractRecords($recordXpath, $fieldMappings) as $record) {
            $preview[] = $record;
            $count++;
            
            if ($count >= $limit) {
                break;
            }
        }
        
        return $preview;
    }
}