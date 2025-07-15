<?php

namespace Crumbls\Importer\States\Configuration;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FieldMappingState extends AbstractState
{
    protected static ?string $title = 'Field Mapping';
    protected static ?string $description = 'Map fields from your source data to the target model';

    /**
     * This state uses forms, so it can use the default GenericFormPage
     */
    public function getRecommendedPageClass(): string
    {
        return \Crumbls\Importer\Filament\Pages\GenericFormPage::class;
    }

    /**
     * This state has a form
     */
    public function hasFilamentForm(): bool
    {
        return true;
    }

    /**
     * Build the field mapping form
     */
    public function buildForm(Schema $schema, ImportContract $record): Schema
    {
        $sourceFields = $this->getSourceFields($record);
        $targetFields = $this->getTargetFields($record);

        return $schema->schema([
            Section::make('Field Mapping Configuration')
                ->description('Map fields from your import source to the target model fields')
                ->schema([
                    Repeater::make('field_mapping')
                        ->label('Field Mappings')
                        ->schema([
                            Select::make('source_field')
                                ->label('Source Field')
                                ->options($sourceFields)
                                ->required()
                                ->searchable(),

                            Select::make('target_field')
                                ->label('Target Field')
                                ->options($targetFields)
                                ->required()
                                ->searchable(),

                            Select::make('transformer')
                                ->label('Data Transformation')
                                ->options([
                                    'none' => 'No transformation',
                                    'trim' => 'Trim whitespace',
                                    'uppercase' => 'Convert to uppercase',
                                    'lowercase' => 'Convert to lowercase',
                                    'date_format' => 'Format as date',
                                    'phone_format' => 'Format phone number',
                                    'email_format' => 'Format email address',
                                ])
                                ->default('none'),

                            TextInput::make('default_value')
                                ->label('Default Value')
                                ->helperText('Used when source field is empty'),
                        ])
                        ->columnSpanFull()
                        ->collapsible()
                        ->defaultItems(1)
                        ->reorderable()
                        ->addActionLabel('Add Field Mapping'),
                ]),

            Section::make('Import Settings')
                ->schema([
                    Select::make('target_model')
                        ->label('Target Model')
                        ->options($this->getAvailableModels())
                        ->required()
                        ->helperText('The model where imported data will be stored'),

                    TextInput::make('batch_size')
                        ->label('Batch Size')
                        ->numeric()
                        ->default(100)
                        ->helperText('Number of records to process at once'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Get default form data
     */
    public function getFormDefaultData(ImportContract $record): array
    {
        return [
            'field_mapping' => $record->field_mapping ?? [],
            'target_model' => $record->target_model ?? null,
            'batch_size' => $record->batch_size ?? 100,
        ];
    }

    /**
     * Handle form save
     */
    public function handleSave(array $data, ImportContract $record): void
    {
        // Save the mapping configuration
        $record->update([
            'field_mapping' => $data['field_mapping'],
            'target_model' => $data['target_model'],
            'batch_size' => $data['batch_size'],
            'configuration_state' => 'field_mapping_complete',
        ]);

        // Transition to next state if we have complete mapping
        if (!empty($data['field_mapping']) && !empty($data['target_model'])) {
            $this->transitionToNextState($record);
        }
    }

    /**
     * Get available source fields from the import driver
     */
    protected function getSourceFields(ImportContract $record): array
    {
        try {
            $driver = $record->getDriver();
            
            if (method_exists($driver, 'getSourceFields')) {
                return $driver->getSourceFields($record);
            }

            // Fallback: try to analyze source
            return $this->analyzeSourceFields($record);
            
        } catch (\Exception $e) {
            return ['field_1' => 'Field 1', 'field_2' => 'Field 2']; // Placeholder
        }
    }

    /**
     * Get available target model fields
     */
    protected function getTargetFields(ImportContract $record): array
    {
        if (!$record->target_model) {
            return [];
        }

        try {
            $model = app($record->target_model);
            $fillable = $model->getFillable();
            
            return array_combine($fillable, array_map('ucwords', $fillable));
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get available models for import targets
     */
    protected function getAvailableModels(): array
    {
        return [
            \App\Models\User::class => 'Users',
            \App\Models\Product::class => 'Products',
            \App\Models\Order::class => 'Orders',
            // Add more models as needed
        ];
    }

    /**
     * Analyze source fields from the import file
     */
    protected function analyzeSourceFields(ImportContract $record): array
    {
        // This would analyze the source file to detect fields
        // For now, return some example fields
        return [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP Code',
        ];
    }

    /**
     * This state should auto-transition once mapping is complete
     */
    public function shouldAutoTransition(ImportContract $record): bool
    {
        return !empty($record->field_mapping) && !empty($record->target_model);
    }
}