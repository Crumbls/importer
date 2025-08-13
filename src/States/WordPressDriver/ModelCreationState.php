<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\ModelGenerator;
use Crumbls\Importer\States\WordPressDriver\ModelCustomizationState;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class ModelCreationState extends AbstractState
{
    protected ModelGenerator $generator;
    
    public function __construct()
    {
        $this->generator = new ModelGenerator();
    }

    protected function prepareModelCreationData(): void
    {
        $unmappedTypes = $this->getStateData('unmapped_for_creation');
        $analysisData = $this->getStateData('analysis');
        
        if (!$unmappedTypes || !$analysisData) {
            $this->setStateData('creation_error', 'Missing required data');
            return;
        }
        
        $creationData = [];
        
        foreach ($unmappedTypes as $postType) {
            $postTypeData = $analysisData['post_types'][$postType] ?? [];
            $metaFields = $this->extractMetaFields($postType, $analysisData);
            
            $creationData[$postType] = [
                'post_type' => $postType,
                'model_name' => Str::studly(Str::singular($postType)),
                'table_name' => Str::snake(Str::plural($postType)),
                'namespace' => 'App\\Models',
                'fillable_fields' => $this->generateFillableFields($postTypeData, $metaFields),
                'relationships' => $this->detectRelationships($postType, $analysisData),
                'create_migration' => true,
                'create_factory' => false,
                'create_seeder' => false,
                'post_count' => $postTypeData['count'] ?? 0,
                'meta_fields' => $metaFields,
            ];
        }
        
        $this->setStateData('model_creation', $creationData);
    }
    
    protected function extractMetaFields(string $postType, array $analysisData): array
    {
        $metaFields = [];
        
        if (isset($analysisData['meta_fields'])) {
            foreach ($analysisData['meta_fields'] as $field) {
                // Filter meta fields that are commonly used with this post type
                // This would require more sophisticated analysis in a real implementation
                $metaFields[] = [
                    'key' => $field['field_name'],
                    'type' => $field['type'],
                    'confidence' => $field['confidence'],
                ];
            }
        }
        
        return $metaFields;
    }
    
    protected function generateFillableFields(array $postTypeData, array $metaFields): array
    {
        // Standard WordPress post fields
        $standardFields = [
            'title',
            'content',
            'excerpt',
            'status',
            'published_at',
            'slug',
        ];
        
        // Add commonly used meta fields as direct model fields
        $metaFieldNames = array_map(fn($meta) => Str::snake($meta['key']), $metaFields);
        
        return array_merge($standardFields, array_slice($metaFieldNames, 0, 10)); // Limit to first 10
    }
    
    protected function detectRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        
        // Common WordPress relationships
        if (isset($analysisData['post_types']['attachment'])) {
            $relationships[] = [
                'type' => 'hasMany',
                'related' => 'App\\Models\\Attachment',
                'method' => 'attachments',
            ];
        }
        
        if (isset($analysisData['post_types']['user'])) {
            $relationships[] = [
                'type' => 'belongsTo',
                'related' => 'App\\Models\\User',
                'method' => 'author',
            ];
        }
        
        return $relationships;
    }

    protected function getDefaultCreationData(array $creationData): array
    {
        return array_values($creationData);
    }
    
    protected function getModelInfo(array $record): string
    {
        $info = [];
        
        if (isset($record['post_count'])) {
            $info[] = "Posts to import: " . number_format($record['post_count']);
        }
        
        if (isset($record['meta_fields'])) {
            $info[] = "Meta fields: " . count($record['meta_fields']);
        }
        
        $filePath = app_path('Models/' . $record['model_name'] . '.php');
        if (File::exists($filePath)) {
            $info[] = "⚠️ Model file already exists";
        }
        
        return implode(' | ', $info);
    }
    
    protected function parseFillableFields(string $fillableFields): array
    {
        return array_map('trim', explode(',', $fillableFields));
    }
    
    protected function updateModelMappings(array $createdModels): void
    {
        $mappingData = $this->getStateData('model_mapping');
        
        foreach ($createdModels as $modelData) {
            $fullClass = $modelData['namespace'] . '\\' . $modelData['model_name'];
            
            $mappingData['mappings'][$modelData['post_type']] = [
                'model_class' => $fullClass,
                'auto_mapped' => false,
                'user_created' => true,
            ];
        }
        
        $this->setStateData('model_mapping', $mappingData);
    }
}