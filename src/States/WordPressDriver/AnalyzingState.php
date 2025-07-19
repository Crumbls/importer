<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Crumbls\Importer\States\Concerns\AnalyzesValues;
use Crumbls\Importer\States\Concerns\StreamingAnalyzesValues;
use Crumbls\Importer\Support\MemoryManager;
use Crumbls\Importer\Facades\Storage;
use Crumbls\StateMachine\State;
use Exception;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzingState extends AbstractState
{
    use AutoTransitionsTrait;
    use AnalyzesValues, StreamingAnalyzesValues {
        AnalyzesValues::analyzeValues insteadof StreamingAnalyzesValues;
        AnalyzesValues::generateRecommendations insteadof StreamingAnalyzesValues;
        StreamingAnalyzesValues::analyzeValuesStreaming insteadof AnalyzesValues;
    }
    
    /**
     * Enable auto-transitions for this state
     */
    protected function hasAutoTransition(): bool
    {
        return true;
    }
    
    /**
     * Configure auto-transition settings
     */
    protected function onAutoTransitionRefresh(ImportContract $record): void
    {
        $this->autoTransitionPollingInterval = 1000; // 1 second
        $this->autoTransitionDelay = 1; // 1 second
    }
    
    /**
     * Recommend a page class that supports infolists
     */
    public function getRecommendedPageClass(): string
    {
        return \Crumbls\Importer\Filament\Pages\GenericInfolistPage::class;
    }
    
    // UI Implementation
    public function getTitle(ImportContract $record): string
    {
        return 'Analyzing WordPress XML';
    }

    public function getHeading(ImportContract $record): string
    {
        return 'WordPress XML Analysis in Progress';
    }

    public function getSubheading(ImportContract $record): ?string
    {
        return 'Analyzing your WordPress XML file to prepare for import...';
    }
    
    public function hasFilamentForm(): bool
    {
        return false; // This state uses infolist, not forms
    }
    
    public function hasFilamentInfolist(): bool
    {
        return true;
    }

    public function buildInfolist(Schema $schema, ImportContract $record): Schema
    {
        // Get WordPress XML analysis data
        $metadata = $record->metadata ?? [];
        $analysis = $metadata['wp_xml_analysis'] ?? [];
        
        // Add sample data if none exists
        if (empty($analysis)) {
            $analysis = [
                'file_size' => 2048576, // 2MB
                'posts_count' => 45,
                'pages_count' => 12,
                'media_count' => 87,
                'comments_count' => 156,
                'categories_count' => 8,
                'tags_count' => 23,
                'custom_post_types_count' => 5,
                'post_types' => [
                    'post' => 45,
                    'page' => 12,
                    'attachment' => 87,
                    'product' => 3,
                    'testimonial' => 2,
                ]
            ];
        }
        
        return $schema->components([
            Section::make('WordPress XML Analysis')
                ->description('Analysis of your WordPress export file')
                ->schema([
                    TextEntry::make('file_info')
                        ->label('Source File')
                        ->state(function () use ($record) {
                            $source = $record->source ?? [];
                            return $source['filename'] ?? 'WordPress Export File';
                        })
                        ->icon('heroicon-o-document-text'),
                        
                    TextEntry::make('file_size')
                        ->label('File Size')
                        ->state(function () use ($analysis) {
                            $bytes = $analysis['file_size'] ?? 0;
                            return $this->formatFileSize($bytes);
                        })
                        ->icon('heroicon-o-scale'),
                ])
                ->columns(2),
                
            Section::make('Content Analysis')
                ->description('Breakdown of content found in your WordPress export')
                ->schema([
                    KeyValueEntry::make('content_stats')
                        ->keyLabel('Content Type')
                        ->valueLabel('Count')
                        ->state(function () use ($analysis) {
                            return [
                                'Posts' => $analysis['posts_count'] ?? 0,
                                'Pages' => $analysis['pages_count'] ?? 0,
                                'Media Items' => $analysis['media_count'] ?? 0,
                                'Comments' => $analysis['comments_count'] ?? 0,
                                'Categories' => $analysis['categories_count'] ?? 0,
                                'Tags' => $analysis['tags_count'] ?? 0,
                                'Custom Post Types' => $analysis['custom_post_types_count'] ?? 0,
                            ];
                        }),
                ]),
                
            Section::make('Post Types Found')
                ->description('All post types detected in your export')
                ->schema([
                    TextEntry::make('post_types')
                        ->label('')
                        ->state(function () use ($analysis) {
                            $postTypes = $analysis['post_types'] ?? [];
                            if (empty($postTypes)) {
                                return 'No post types detected yet...';
                            }
                            return collect($postTypes)->map(function ($count, $type) {
                                return "**{$type}**: {$count} items";
                            })->join(' • ');
                        })
                        ->markdown(),
                ])
                ->hidden(fn () => empty($analysis['post_types'] ?? [])),
                
            Section::make('Import Settings')
                ->description('Configuration for this import')
                ->schema([
                    TextEntry::make('driver')
                        ->label('Import Driver')
                        ->state('WordPress XML Driver')
                        ->icon('heroicon-o-cog-6-tooth'),
                        
                    TextEntry::make('status')
                        ->label('Current Status')
                        ->state('Analyzing...')
                        ->color('warning')
                        ->icon('heroicon-o-magnifying-glass'),
                ])
                ->columns(2),
        ]);
    }
    
    /**
     * Format file size in human readable format
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return 'Unknown';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    public function getHeaderActions(ImportContract $record): array
    {
        return [];
    }
    
    public function onEnter(): void
    {
        $import = $this->getRecord();

        $metadata = $import->metadata ?? [];

        if (!isset($metadata['storage_driver']) || !$metadata['storage_driver']) {
            throw new \RuntimeException('No storage driver found in metadata');
        }

        // Get the storage driver
        $storage = Storage::driver($metadata['storage_driver'])
            ->configureFromMetadata($metadata);

        $metadata['parsing_stats'] = is_array($metadata['parsing_stats'] ?? null) ? $metadata['parsing_stats'] : [];

        $metadata['data_map'] = $storage->tableExists('posts') ? $this->analyzePostTypes($storage) : [];

        $import->update([
            'metadata' => $metadata
        ]);
        
        // Prepare analysis data for the mapping state
        $this->prepareAnalysisForMappingState($metadata);
    }
    
    /**
     * Prepare analysis data for the mapping state
     */
    protected function prepareAnalysisForMappingState(array $metadata): void
    {
        $dataMap = $metadata['data_map'] ?? [];
        
        // Transform the data structure for the mapping state
        $analysisData = [
            'post_types' => $this->extractPostTypes($dataMap),
            'meta_fields' => $this->extractMetaFields($dataMap),
            'post_columns' => $this->extractPostColumns($dataMap),
            'field_analysis' => $dataMap, // Keep original for reference
            'extraction_stats' => $metadata['parsing_stats'] ?? [],
        ];
        
        // Store in state data for next states
        $this->setStateData('analysis', $analysisData);
    }
    
    /**
     * Extract post types from data map
     */
    protected function extractPostTypes(array $dataMap): array
    {
        $postTypes = [];
        
        // Get the storage driver to analyze post types
        $storage = $this->getStorageDriver();
        
        if ($storage && method_exists($storage, 'db')) {
            $connection = $storage->db();
            
            // Get post type counts
            $postTypeCounts = $connection->table('posts')
                ->select('post_type')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('post_type')
                ->get()
                ->pluck('count', 'post_type')
                ->toArray();
                
            foreach ($postTypeCounts as $postType => $count) {
                $postTypes[$postType] = [
                    'count' => $count,
                    'type' => $postType,
                    'description' => ucfirst($postType) . ' content type',
                ];
            }
        }
        
        return $postTypes;
    }
    
    /**
     * Extract meta fields from data map
     */
    protected function extractMetaFields(array $dataMap): array
    {
        $metaFields = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'meta_field') {
                $metaFields[] = $field;
            }
        }
        
        return $metaFields;
    }
    
    /**
     * Extract post columns from data map
     */
    protected function extractPostColumns(array $dataMap): array
    {
        $postColumns = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'post_column') {
                $postColumns[] = $field;
            }
        }
        
        return $postColumns;
    }
    
    /**
     * Get the storage driver from metadata
     */
    protected function getStorageDriver()
    {
        $import = $this->getRecord();
        $metadata = $import->metadata ?? [];
        
        if (!isset($metadata['storage_driver'])) {
            return null;
        }
        
        return Storage::driver($metadata['storage_driver'])
            ->configureFromMetadata($metadata);
    }

    /**
     * TODO: Make this work with any of the storage drivers.  This is a late game task.
     * @param $storage
     * @return array
     * @throws \Exception
     */
    protected function analyzePostTypes($storage): array
    {
        if (!method_exists($storage, 'db')) {
            throw new Exception('Storage driver is not yet valid.');
        }

        $connection = $storage->db();

        // Get the actual column names from the table structure  
        $defaultFields = array_keys((array)$connection
            ->table('posts')
            ->take(1)
            ->inRandomOrder()
            ->get()
            ->first());

        $results = [];

        // Analyze default post table columns
        foreach ($defaultFields as $fieldName) {
            $analysis = $this->analyzePostTableColumn($connection, $fieldName, null);
            $analysis['field_name'] = $fieldName;
            $analysis['field_type'] = 'post_column';
            $results[] = $analysis;
        }

        // Get all meta keys and analyze them
        $metaKeys = $connection
            ->table('postmeta')
            ->select('meta_key')
            ->distinct()
            ->pluck('meta_key');

        // Continue adding to existing results array
        foreach ($metaKeys as $metaKey) {
            $analysis = $this->analyzeMetaFieldEfficiently($connection, $metaKey);
            $analysis['field_name'] = $metaKey;
            $analysis['field_type'] = 'meta_field';
            $results[] = $analysis;
        }

        return $results;
    }
    
    /**
     * Analyze a post table column efficiently using sampling
     */
    private function analyzePostTableColumn($connection, string $fieldName, ?string $postType): array
    {
        // Use chunked processing for large datasets
        $chunkSize = 100;
        $maxSamples = 1000; // Limit total samples for analysis
        $samples = collect();
        
        $query = $connection->table('posts')
            ->whereNotNull($fieldName)
            ->where($fieldName, '!=', '')
            ->select($fieldName)
            ->orderBy($fieldName);
            
        // Add post type filter if specified
        if ($postType !== null) {
            $query->where('post_type', $postType);
        }
        
        $query->chunk($chunkSize, function ($chunk) use (&$samples, $maxSamples, $fieldName) {
            foreach ($chunk as $row) {
                if ($samples->count() >= $maxSamples) {
                    return false; // Stop chunking
                }
                $samples->push($row->$fieldName);
            }
        });
        
        return $this->analyzeValues($samples);
    }
    
    /**
     * 🚀 MEMORY OPTIMIZED: Analyze meta field using streaming analysis
     */
    private function analyzeMetaFieldEfficiently($connection, string $metaKey): array
    {
        // Get basic statistics first
        $stats = $connection->table('postmeta')
            ->where('meta_key', $metaKey)
            ->selectRaw('COUNT(*) as total_count, COUNT(DISTINCT meta_value) as unique_count')
            ->first();
        
        $totalCount = $stats->total_count;
        $uniqueCount = $stats->unique_count;
        
        // Initialize memory manager for large datasets
        $memoryManager = null;
        if ($totalCount > 10000) {
            $memoryManager = app(MemoryManager::class);
            $memoryManager->startMonitoring([
                'max_memory_usage' => '256M', // Reasonable limit
                'warning_threshold' => 0.8,   // 80% of memory limit
                'batch_size' => 1000,         // Starting batch size
            ]);
        }
        
        // For small datasets, use fast original method
        if ($totalCount <= 1000) {
            $values = $connection->table('postmeta')
                ->where('meta_key', $metaKey)
                ->pluck('meta_value');
            
            return $this->analyzeValues($values);
        }
        
        // For large datasets, use streaming analysis
        $maxSamples = min(5000, max(1000, $totalCount * 0.05)); // 5% sample, min 1K, max 5K
        
        // Create data provider that yields batches
        $dataProvider = function() use ($connection, $metaKey, $memoryManager) {
            $batchSize = $memoryManager ? $memoryManager->getCurrentBatchSize() : 1000;
            $offset = 0;
            
            while (true) {
                $chunk = $connection->table('postmeta')
                    ->where('meta_key', $metaKey)
                    ->whereNotNull('meta_value')
                    ->where('meta_value', '!=', '')
                    ->orderBy('meta_id') // Use indexed column for consistent ordering
                    ->skip($offset)
                    ->take($batchSize)
                    ->get();
                
                if ($chunk->isEmpty()) {
                    break; // No more data
                }
                
                // Monitor memory pressure during processing
                if ($memoryManager) {
                    $memoryManager->monitor();
                    
                    // Adjust batch size if memory pressure detected
                    if ($memoryManager->shouldReduceBatchSize()) {
                        $memoryManager->adjustBatchSize(0.7); // Reduce by 30%
                        $batchSize = $memoryManager->getCurrentBatchSize();
                    }
                }
                
                // Yield the values from this chunk
                yield $chunk->pluck('meta_value');
                
                $offset += $batchSize;
            }
        };
        
        // Use streaming analysis
        $analysis = $this->analyzeValuesStreaming($dataProvider, $maxSamples, $memoryManager);
        
        // Add metadata about the analysis
        $analysis['sampling_info'] = [
            'total_records' => $totalCount,
            'unique_records' => $uniqueCount,
            'sample_size' => $analysis['breakdown']['total_count'] ?? 0,
            'is_sampled' => $totalCount > 1000,
            'uniqueness_ratio' => $totalCount > 0 ? round($uniqueCount / $totalCount * 100, 2) : 0,
            'memory_optimized' => $totalCount > 10000,
            'memory_stats' => $memoryManager ? $memoryManager->getMemoryStats() : null,
        ];
        
        // Clean up memory manager
        if ($memoryManager) {
            $memoryManager->cleanup();
        }
        
        return $analysis;
    }
}