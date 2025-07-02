<?php

declare(strict_types=1);

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate Filament Admin Resource from schema analysis
 */
class GenerateFilamentResourceStep
{
    public function execute(PipelineContext $context): void
    {
        $schema = $context->get('schema_analysis');
        if (!$schema) {
            throw new \RuntimeException('No schema analysis available for Filament resource generation');
        }
        
        $resourceContent = $this->generateResourceContent($schema, $context);
        $resourcePath = $this->getResourcePath($schema['model_name']);
        
        // Ensure Filament Resources directory exists
        $this->ensureDirectoryExists(dirname($resourcePath));
        
        // Write resource file
        File::put($resourcePath, $resourceContent);
        
        $context->set('filament_generation_result', [
            'created' => true,
            'resource_name' => $schema['model_name'] . 'Resource',
            'resource_path' => $resourcePath,
            'model_name' => $schema['model_name'],
            'table_name' => $schema['table_name'],
            'fields_count' => count($schema['fields']),
            'filters_count' => $this->countGeneratedFilters($schema['fields'])
        ]);
    }
    
    protected function generateResourceContent(array $schema, PipelineContext $context): string
    {
        $modelName = $schema['model_name'];
        $resourceName = $modelName . 'Resource';
        $tableName = $schema['table_name'];
        $fields = $schema['fields'];
        
        return "<?php

namespace App\Filament\Resources;

use App\Filament\Resources\\{$resourceName}\Pages;
use App\Models\\{$modelName};
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * {$resourceName}
 * 
 * Generated automatically from CSV import
 * Table: {$tableName}
 * Fields: " . count($fields) . "
 */
class {$resourceName} extends Resource
{
    protected static ?\$model = {$modelName}::class;
    
    protected static ?\$navigationIcon = 'heroicon-o-rectangle-stack';
    
    protected static ?\$navigationGroup = 'Imported Data';
    
    protected static ?\$recordTitleAttribute = '{$this->getPrimaryDisplayField($fields)}';
    
    public static function form(Form \$form): Form
    {
        return \$form
            ->schema([
{$this->generateFormFields($fields)}
            ]);
    }
    
    public static function table(Table \$table): Table
    {
        return \$table
            ->columns([
{$this->generateTableColumns($fields)}
            ])
            ->filters([
{$this->generateTableFilters($fields)}
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
{$this->generateBulkActions($schema)}
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->striped()
            ->searchable();
    }
    
    public static function getRelations(): array
    {
        return [
{$this->generateRelations($schema)}
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\List{$modelName}::route('/'),
            'create' => Pages\Create{$modelName}::route('/create'),
            'view' => Pages\View{$modelName}::route('/{record}'),
            'edit' => Pages\Edit{$modelName}::route('/{record}/edit'),
        ];
    }
    
{$this->generateGlobalSearch($fields)}
{$this->generateCustomMethods($schema)}
}
";
    }
    
    protected function generateFormFields(array $fields): string
    {
        $formFields = [];
        
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            $nullable = $field['nullable'];
            $unique = $field['unique'];
            
            // Skip system fields
            if (in_array($fieldName, ['id', 'created_at', 'updated_at'])) {
                continue;
            }
            
            $formField = $this->generateSingleFormField($fieldName, $fieldType, $nullable, $unique);
            $formFields[] = "                {$formField}";
        }
        
        return implode("\n", $formFields);
    }
    
    protected function generateSingleFormField(string $fieldName, string $fieldType, bool $nullable, bool $unique): string
    {
        $label = Str::title(str_replace('_', ' ', $fieldName));
        $required = $nullable ? '' : '->required()';
        $uniqueRule = $unique ? '->unique(ignoreRecord: true)' : '';
        
        $baseField = match($fieldType) {
            'boolean' => "Forms\Components\Toggle::make('{$fieldName}')",
            'text' => "Forms\Components\Textarea::make('{$fieldName}')\n                    ->rows(3)",
            'json' => "Forms\Components\KeyValue::make('{$fieldName}')",
            'timestamp', 'date' => "Forms\Components\DateTimePicker::make('{$fieldName}')",
            'decimal' => "Forms\Components\TextInput::make('{$fieldName}')\n                    ->numeric()\n                    ->step(0.01)",
            'integer' => "Forms\Components\TextInput::make('{$fieldName}')\n                    ->numeric()\n                    ->integer()",
            default => $this->generateTextInputForField($fieldName)
        };
        
        return $baseField . "\n                    ->label('{$label}'){$required}{$uniqueRule},";
    }
    
    protected function generateTextInputForField(string $fieldName): string
    {
        // Special handling for common field patterns
        if (str_contains($fieldName, 'email')) {
            return "Forms\Components\TextInput::make('{$fieldName}')\n                    ->email()";
        }
        
        if (str_contains($fieldName, 'url') || str_contains($fieldName, 'website')) {
            return "Forms\Components\TextInput::make('{$fieldName}')\n                    ->url()";
        }
        
        if (str_contains($fieldName, 'phone')) {
            return "Forms\Components\TextInput::make('{$fieldName}')\n                    ->tel()";
        }
        
        if (str_contains($fieldName, 'password')) {
            return "Forms\Components\TextInput::make('{$fieldName}')\n                    ->password()\n                    ->revealable()";
        }
        
        if (str_contains($fieldName, 'status') || str_contains($fieldName, 'type') || str_contains($fieldName, 'category')) {
            return "Forms\Components\Select::make('{$fieldName}')\n                    ->options([\n                        // Add options based on your data\n                    ])";
        }
        
        return "Forms\Components\TextInput::make('{$fieldName}')";
    }
    
    protected function generateTableColumns(array $fields): string
    {
        $columns = [];
        $displayedCount = 0;
        $maxColumns = 8; // Limit table columns for readability
        
        foreach ($fields as $field) {
            if ($displayedCount >= $maxColumns) {
                break;
            }
            
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            
            // Always show ID and primary display field
            if ($fieldName === 'id' || $this->isPrimaryDisplayField($fieldName)) {
                $column = $this->generateSingleTableColumn($fieldName, $fieldType, true);
                $columns[] = "                {$column}";
                $displayedCount++;
                continue;
            }
            
            // Skip less important fields to keep table clean
            if (in_array($fieldName, ['created_at', 'updated_at']) && $displayedCount > 5) {
                continue;
            }
            
            $column = $this->generateSingleTableColumn($fieldName, $fieldType, false);
            $columns[] = "                {$column}";
            $displayedCount++;
        }
        
        return implode("\n", $columns);
    }
    
    protected function generateSingleTableColumn(string $fieldName, string $fieldType, bool $isPrimary): string
    {
        $label = Str::title(str_replace('_', ' ', $fieldName));
        $searchable = $isPrimary || in_array($fieldName, ['name', 'title', 'email']) ? '->searchable()' : '';
        $sortable = '->sortable()';
        
        $baseColumn = match($fieldType) {
            'boolean' => "Tables\Columns\IconColumn::make('{$fieldName}')\n                    ->boolean()",
            'timestamp', 'date' => "Tables\Columns\TextColumn::make('{$fieldName}')\n                    ->dateTime()\n                    ->since()",
            'decimal' => "Tables\Columns\TextColumn::make('{$fieldName}')\n                    ->money('USD')",
            'json' => "Tables\Columns\TextColumn::make('{$fieldName}')\n                    ->limit(50)\n                    ->tooltip(function (\$record) { return json_encode(\$record->{$fieldName}); })",
            default => "Tables\Columns\TextColumn::make('{$fieldName}')" . ($fieldType === 'text' ? "\n                    ->limit(50)" : "")
        };
        
        return $baseColumn . "\n                    ->label('{$label}'){$searchable}{$sortable},";
    }
    
    protected function generateTableFilters(array $fields): string
    {
        $filters = [];
        
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            
            // Generate filters for common filterable fields
            if ($this->shouldGenerateFilter($fieldName, $fieldType)) {
                $filter = $this->generateSingleFilter($fieldName, $fieldType);
                if ($filter) {
                    $filters[] = "                {$filter}";
                }
            }
        }
        
        return implode("\n", $filters);
    }
    
    protected function generateSingleFilter(string $fieldName, string $fieldType): ?string
    {
        $label = Str::title(str_replace('_', ' ', $fieldName));
        
        return match(true) {
            $fieldType === 'boolean' => "Tables\Filters\TernaryFilter::make('{$fieldName}')\n                    ->label('{$label}'),",
            str_contains($fieldName, 'status') || str_contains($fieldName, 'type') => "Tables\Filters\SelectFilter::make('{$fieldName}')\n                    ->label('{$label}')\n                    ->options([\n                        // Add options based on your data\n                    ]),",
            $fieldType === 'timestamp' || $fieldType === 'date' => "Tables\Filters\Filter::make('{$fieldName}')\n                    ->form([\n                        Forms\Components\DatePicker::make('{$fieldName}_from'),\n                        Forms\Components\DatePicker::make('{$fieldName}_until'),\n                    ])\n                    ->query(function (Builder \$query, array \$data): Builder {\n                        return \$query\n                            ->when(\$data['{$fieldName}_from'], fn (\$query, \$date) => \$query->whereDate('{$fieldName}', '>=', \$date))\n                            ->when(\$data['{$fieldName}_until'], fn (\$query, \$date) => \$query->whereDate('{$fieldName}', '<=', \$date));\n                    }),",
            default => null
        };
    }
    
    protected function generateBulkActions(array $schema): string
    {
        $actions = [];
        
        // Export action
        $actions[] = "                    Tables\Actions\ExportBulkAction::make()
                        ->label('Export Selected')
                        ->icon('heroicon-o-arrow-down-tray'),";
        
        return implode("\n", $actions);
    }
    
    protected function generateRelations(array $schema): string
    {
        $relations = [];
        
        if (isset($schema['relationships'])) {
            foreach ($schema['relationships'] as $relationship) {
                $relationName = $relationship['method_name'];
                $relatedModel = $relationship['related_model'];
                $relations[] = "            // {$relationName}RelationManager::class,";
            }
        }
        
        return implode("\n", $relations);
    }
    
    protected function generateGlobalSearch(array $fields): string
    {
        $searchableFields = [];
        
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            if (in_array($fieldName, ['name', 'title', 'email', 'description'])) {
                $searchableFields[] = "'{$fieldName}'";
            }
        }
        
        if (empty($searchableFields)) {
            return '';
        }
        
        return "    
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with([]);
    }
    
    public static function getGloballySearchableAttributes(): array
    {
        return [" . implode(', ', $searchableFields) . "];
    }";
    }
    
    protected function generateCustomMethods(array $schema): string
    {
        $methods = [];
        
        // Add navigation badge if appropriate
        $methods[] = "    
    public static function getNavigationBadge(): ?string
    {
        return static::\$model::count();
    }";
        
        return implode("\n", $methods);
    }
    
    protected function getPrimaryDisplayField(array $fields): string
    {
        $preferredFields = ['name', 'title', 'email', 'username'];
        
        foreach ($preferredFields as $preferred) {
            foreach ($fields as $field) {
                if ($field['name'] === $preferred) {
                    return $preferred;
                }
            }
        }
        
        // Return first non-system field
        foreach ($fields as $field) {
            if (!in_array($field['name'], ['id', 'created_at', 'updated_at'])) {
                return $field['name'];
            }
        }
        
        return 'id';
    }
    
    protected function isPrimaryDisplayField(string $fieldName): bool
    {
        return in_array($fieldName, ['name', 'title', 'email', 'username']);
    }
    
    protected function shouldGenerateFilter(string $fieldName, string $fieldType): bool
    {
        // Generate filters for common filterable fields
        return $fieldType === 'boolean' || 
               str_contains($fieldName, 'status') || 
               str_contains($fieldName, 'type') || 
               str_contains($fieldName, 'category') ||
               $fieldType === 'timestamp' ||
               $fieldType === 'date';
    }
    
    protected function countGeneratedFilters(array $fields): int
    {
        $count = 0;
        foreach ($fields as $field) {
            if ($this->shouldGenerateFilter($field['name'], $field['type'])) {
                $count++;
            }
        }
        return $count;
    }
    
    protected function getResourcePath(string $modelName): string
    {
        return app_path("Filament/Resources/{$modelName}Resource.php");
    }
    
    protected function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}