<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\Console\ModelConfigurator;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Infolist;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ConfigureModelsState extends AbstractState {
    protected ?Command $command = null;
    
    public function setCommand(?Command $command): void
    {
        $this->command = $command;
    }

    public function form(Form $form): Form
    {
        $import = $this->getImport();
        $metadata = $import->metadata ?? [];
        $modelConfiguration = $metadata['model_configuration'] ?? [];

        return $form
            ->schema([
                Section::make('Model Configuration')
                    ->description('Configure how your data will be imported into models')
                    ->schema([
                        TextInput::make('configuration_version')
                            ->label('Configuration Version')
                            ->default($modelConfiguration['configuration_version'] ?? '1.0')
                            ->disabled(),

                        KeyValue::make('models')
                            ->label('Model Mappings')
                            ->keyLabel('Model Name')
                            ->valueLabel('Configuration')
                            ->default($modelConfiguration['models'] ?? [])
                            ->disabled(),
                    ]),
            ])
            ->statePath('data');
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $import = $this->getImport();
        $metadata = $import->metadata ?? [];
        $analysisResults = $metadata['analysis_results'] ?? [];
        $modelConfiguration = $metadata['model_configuration'] ?? [];

        return $infolist
            ->schema([
                InfoSection::make('Analysis Results')
                    ->description('Data analysis from your import file')
                    ->schema([
                        TextEntry::make('summary.total_posts')
                            ->label('Total Posts')
                            ->default($analysisResults['summary']['total_posts'] ?? 0),

                        TextEntry::make('summary.total_users')
                            ->label('Total Users')
                            ->default($analysisResults['summary']['total_users'] ?? 0),

                        KeyValueEntry::make('summary.post_type_distribution')
                            ->label('Post Type Distribution')
                            ->default($analysisResults['summary']['post_type_distribution'] ?? []),
                    ]),

                InfoSection::make('Model Configuration')
                    ->description('Current model configuration settings')
                    ->schema([
                        TextEntry::make('configuration_version')
                            ->label('Configuration Version')
                            ->default($modelConfiguration['configuration_version'] ?? 'Not configured'),

                        TextEntry::make('configured_at')
                            ->label('Configured At')
                            ->default($modelConfiguration['configured_at'] ?? 'Not configured'),

                        KeyValueEntry::make('models')
                            ->label('Configured Models')
                            ->default($modelConfiguration['models'] ?? []),
                    ]),
            ])
            ->record($import);
    }

    public function hasInfolist(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return 'Configure Models';
    }

    public function getHeading(): string
    {
        return 'Configure Import Models';
    }

    public function getSubheading(): string
    {
        return 'Configure how your data will be imported into Laravel models';
    }
    
    public function onEnter(): void
    {
        $import = $this->getImport();
        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

        try {
            $metadata = $import->metadata ?? [];
            
            if (!isset($metadata['analysis_results'])) {
                throw new \RuntimeException('Analysis results not found. Run AnalyzingState first.');
            }

            // Generate model suggestions based on analysis
            $suggestions = $this->generateModelSuggestions($metadata['analysis_results']);

            // If running in console, use interactive configurator
            if (app()->runningInConsole()) {
                // Try to get the current console command from the Laravel application
                try {
                    // Check if we can access the current command through the container
                    $artisan = app(\Illuminate\Contracts\Console\Kernel::class);
                    
                    // Create a mock command for the configurator to use
                    $mockCommand = new class extends \Illuminate\Console\Command {
                        protected $signature = 'configure-models';
                        protected $description = 'Configure models for import';
                        
                        public function __construct() {
                            parent::__construct();
                            // Set up input/output streams
                            $this->input = new \Symfony\Component\Console\Input\ArrayInput([]);
                            $this->output = new \Symfony\Component\Console\Output\ConsoleOutput();
                        }
                    };
                    
                    $configurator = new ModelConfigurator($mockCommand);
                    $configuration = $configurator->configure($suggestions);
                } catch (\Exception $e) {
                    // Fall back to non-interactive mode if console setup fails
                    $configuration = [
                        'models' => $suggestions,
                        'configured_at' => now()->toISOString(),
                        'configuration_version' => '1.0',
                        'console_fallback' => true,
                        'console_error' => $e->getMessage()
                    ];
                }
            } else {
                // For non-console (API/web), use suggestions as-is
                $configuration = [
                    'models' => $suggestions,
                    'configured_at' => now()->toISOString(),
                    'configuration_version' => '1.0'
                ];
            }

            // Update import metadata with configuration
            $import->update([
                'metadata' => array_merge($metadata, [
                    'model_configuration' => $configuration,
                    'models_configured' => true,
                    'configured_at' => now()->toISOString(),
                ])
            ]);

        } catch (\Exception $e) {
            $import->update([
                'state' => FailedState::class,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            throw $e;
        }
    }

    protected function generateModelSuggestions(array $analysis): array
    {
        $suggestions = [];

        // 1. Generate models based on post types
        if (isset($analysis['post_types'])) {
            foreach ($analysis['post_types'] as $postType => $data) {
                if ($data['total_count'] > 0 && !in_array($postType, ['revision', 'nav_menu_item'])) {
                    $modelName = $this->suggestModelName($postType);
                    
                    $suggestions[$modelName] = [
                        'table_name' => $this->suggestTableName($postType),
                        'source_table' => 'posts',
                        'source_conditions' => ['post_type' => $postType],
                        'estimated_records' => $data['total_count'],
                        'configured' => false,
                        'fields' => $this->suggestPostFields($postType, $analysis),
                        'suggested_relationships' => $this->suggestPostRelationships($postType)
                    ];
                }
            }
        }

        // 2. Generate models for taxonomies
        if (isset($analysis['taxonomies'])) {
            foreach ($analysis['taxonomies'] as $taxonomy => $data) {
                if ($data['term_count'] > 0 && !in_array($taxonomy, ['nav_menu'])) {
                    $modelName = $this->suggestModelName($taxonomy, true);
                    
                    $suggestions[$modelName] = [
                        'table_name' => $this->suggestTableName($taxonomy),
                        'source_table' => 'terms',
                        'source_conditions' => ['taxonomy' => $taxonomy],
                        'estimated_records' => $data['term_count'],
                        'configured' => false,
                        'fields' => $this->suggestTermFields(),
                        'suggested_relationships' => $this->suggestTermRelationships()
                    ];
                }
            }
        }

        // 3. Standard models
        if (isset($analysis['users']) && $analysis['users']['total_count'] > 0) {
            $suggestions['User'] = [
                'table_name' => 'users',
                'source_table' => 'users',
                'source_conditions' => [],
                'estimated_records' => $analysis['users']['total_count'],
                'configured' => false,
                'fields' => $this->suggestUserFields(),
                'suggested_relationships' => $this->suggestUserRelationships()
            ];
        }

        // 4. Comments (if they exist)
        if (isset($analysis['summary']['total_posts']) && $analysis['summary']['total_posts'] > 0) {
            // Check if there are comments in the data
            $suggestions['Comment'] = [
                'table_name' => 'comments',
                'source_table' => 'comments',
                'source_conditions' => [],
                'estimated_records' => '?',
                'configured' => false,
                'fields' => $this->suggestCommentFields(),
                'suggested_relationships' => $this->suggestCommentRelationships()
            ];
        }

        return $suggestions;
    }

    protected function suggestModelName(string $postType, bool $isTaxonomy = false): string
    {
        if ($isTaxonomy) {
            // For taxonomies, singularize the name
            return Str::studly(Str::singular($postType));
        }

        // For post types, handle special cases
        $modelNames = [
            'post' => 'Post',
            'page' => 'Page',
            'attachment' => 'Attachment',
            'portfolio' => 'Portfolio',
            'product' => 'Product',
            'event' => 'Event',
            'testimonial' => 'Testimonial',
            'team' => 'TeamMember',
            'slider' => 'Slide',
        ];

        return $modelNames[$postType] ?? Str::studly($postType);
    }

    protected function suggestTableName(string $postType): string
    {
        // Convert to snake_case and pluralize
        $tableName = Str::snake($postType);
        
        // Handle special pluralization cases
        $specialCases = [
            'team' => 'team_members',
            'slider' => 'slides',
            'testimonial' => 'testimonials',
        ];

        return $specialCases[$tableName] ?? Str::plural($tableName);
    }

    protected function suggestPostFields(string $postType, array $analysis): array
    {
        $baseFields = [
            'id' => ['source' => 'post_id', 'type' => 'bigInteger', 'primary' => true, 'nullable' => false],
            'title' => ['source' => 'post_title', 'type' => 'string', 'nullable' => true],
            'content' => ['source' => 'post_content', 'type' => 'text', 'nullable' => true],
            'excerpt' => ['source' => 'post_excerpt', 'type' => 'text', 'nullable' => true],
            'status' => ['source' => 'post_status', 'type' => 'string', 'nullable' => false],
            'published_at' => ['source' => 'post_date', 'type' => 'timestamp', 'nullable' => true],
            'created_at' => ['source' => 'created_at', 'type' => 'timestamp', 'nullable' => false],
            'updated_at' => ['source' => 'updated_at', 'type' => 'timestamp', 'nullable' => false],
        ];

        // Customize fields based on post type
        if ($postType === 'attachment') {
            $baseFields['url'] = ['source' => 'guid', 'type' => 'string', 'nullable' => true];
            unset($baseFields['content'], $baseFields['excerpt']);
        } elseif ($postType === 'page') {
            $baseFields['slug'] = ['source' => 'post_name', 'type' => 'string', 'nullable' => true];
        } elseif (in_array($postType, ['portfolio', 'product', 'event'])) {
            $baseFields['slug'] = ['source' => 'post_name', 'type' => 'string', 'nullable' => true];
            $baseFields['featured_image'] = ['source' => 'meta:_thumbnail_id', 'type' => 'bigInteger', 'nullable' => true];
        }

        return $baseFields;
    }

    protected function suggestTermFields(): array
    {
        return [
            'id' => ['source' => 'term_id', 'type' => 'bigInteger', 'primary' => true, 'nullable' => false],
            'name' => ['source' => 'name', 'type' => 'string', 'nullable' => false],
            'slug' => ['source' => 'slug', 'type' => 'string', 'nullable' => false],
            'description' => ['source' => 'description', 'type' => 'text', 'nullable' => true],
            'created_at' => ['source' => 'created_at', 'type' => 'timestamp', 'nullable' => false],
            'updated_at' => ['source' => 'updated_at', 'type' => 'timestamp', 'nullable' => false],
        ];
    }

    protected function suggestUserFields(): array
    {
        return [
            'id' => ['source' => 'user_id', 'type' => 'bigInteger', 'primary' => true, 'nullable' => false],
            'username' => ['source' => 'login', 'type' => 'string', 'nullable' => false],
            'email' => ['source' => 'email', 'type' => 'string', 'nullable' => true],
            'display_name' => ['source' => 'display_name', 'type' => 'string', 'nullable' => true],
            'first_name' => ['source' => 'first_name', 'type' => 'string', 'nullable' => true],
            'last_name' => ['source' => 'last_name', 'type' => 'string', 'nullable' => true],
            'created_at' => ['source' => 'created_at', 'type' => 'timestamp', 'nullable' => false],
            'updated_at' => ['source' => 'updated_at', 'type' => 'timestamp', 'nullable' => false],
        ];
    }

    protected function suggestCommentFields(): array
    {
        return [
            'id' => ['source' => 'comment_id', 'type' => 'bigInteger', 'primary' => true, 'nullable' => false],
            'post_id' => ['source' => 'post_id', 'type' => 'bigInteger', 'nullable' => false],
            'author_name' => ['source' => 'comment_author', 'type' => 'string', 'nullable' => true],
            'author_email' => ['source' => 'comment_author_email', 'type' => 'string', 'nullable' => true],
            'content' => ['source' => 'comment_content', 'type' => 'text', 'nullable' => false],
            'status' => ['source' => 'comment_approved', 'type' => 'string', 'nullable' => false],
            'commented_at' => ['source' => 'comment_date', 'type' => 'timestamp', 'nullable' => false],
            'created_at' => ['source' => 'created_at', 'type' => 'timestamp', 'nullable' => false],
            'updated_at' => ['source' => 'updated_at', 'type' => 'timestamp', 'nullable' => false],
        ];
    }

    // Placeholder relationship suggestions
    protected function suggestPostRelationships(): array
    {
        return [
            'comments' => ['type' => 'hasMany', 'model' => 'Comment'],
            'author' => ['type' => 'belongsTo', 'model' => 'User'],
        ];
    }

    protected function suggestTermRelationships(): array
    {
        return [
            'posts' => ['type' => 'belongsToMany', 'model' => 'Post', 'pivot' => 'term_relationships'],
        ];
    }

    protected function suggestUserRelationships(): array
    {
        return [
            'posts' => ['type' => 'hasMany', 'model' => 'Post'],
            'comments' => ['type' => 'hasMany', 'model' => 'Comment'],
        ];
    }

    protected function suggestCommentRelationships(): array
    {
        return [
            'post' => ['type' => 'belongsTo', 'model' => 'Post'],
        ];
    }

    /**
     * Get custom pages for the configure models state
     */
    public static function getCustomPages(ImportContract $record): array
    {
        $pages = [];
        
        // Only show configure page if we're in this state or have model configuration
        $metadata = $record->metadata ?? [];
        $currentState = $record->getStateMachine()->getCurrentState();
        
        if ($currentState instanceof static || isset($metadata['model_configuration'])) {
            $pages[] = [
                'key' => 'configure-models',
                'name' => 'Configure Models',
                'class' => ConfigureModelsPage::class,
                'route' => '/{record}/configure-models',
                'icon' => 'heroicon-o-adjustments-horizontal',
                'sort' => 30,
                'available' => function($record) {
                    $metadata = $record->metadata ?? [];
                    return isset($metadata['analysis_results']) && !empty($metadata['analysis_results']);
                }
            ];
        }
        
        return $pages;
    }
}