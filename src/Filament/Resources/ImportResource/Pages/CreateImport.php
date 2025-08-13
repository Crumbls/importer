<?php

namespace Crumbls\Importer\Filament\Resources\ImportResource\Pages;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Filament\Resources\ImportResource;
use Crumbls\Importer\Traits\IsDiskAware;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Filament\Forms\Components\Wizard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class CreateImport extends CreateRecord
{
    use IsDiskAware;
    
    protected static string $resource = ImportResource::class;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make('Data Source')
                        ->schema([
                            Forms\Components\Select::make('source_type')
                                ->label('Source Type')
                                ->options([
                                    'csv' => 'CSV File',
                                    'tsv' => 'TSV File', 
                                    'xml' => 'XML File',
                                    'wpxml' => 'WordPress XML File',
                                    'database' => 'Database Connection',
                                ])
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, $set) {
                                    $set('source_detail', null);
                                    $set('database_connection', null);
                                    $set('uploaded_file', null);
                                    $set('selected_file', null);
                                }),

                            // File-based source options
                            Forms\Components\Group::make([
                                Forms\Components\Tabs::make('File Source')
                                    ->tabs([
                                        Forms\Components\Tabs\Tab::make('Upload File')
                                            ->schema([
                                                Forms\Components\FileUpload::make('uploaded_file')
                                                    ->label('Upload File')
                                                    ->acceptedFileTypes([
                                                        'text/csv',
                                                        'text/tab-separated-values',
                                                        'application/xml',
                                                        'text/xml',
                                                    ])
                                                    ->maxSize(102400) // 100MB
                                                    ->disk('local')
                                                    ->directory('imports/uploads')
                                                    ->preserveFilenames()
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, $set) {
                                                        if ($state) {
                                                            $set('selected_file', null);
                                                            $set('source_detail', json_encode([
                                                                'disk' => 'local',
                                                                'path' => "imports/uploads/{$state}",
                                                                'type' => 'uploaded'
                                                            ]));
                                                        }
                                                    }),
                                            ]),
                                        
                                        Forms\Components\Tabs\Tab::make('Browse Files')
                                            ->schema([
                                                Forms\Components\Select::make('storage_disk')
                                                    ->label('Storage Disk')
                                                    ->options(function () {
                                                        return collect($this->getAvailableDisks())
                                                            ->mapWithKeys(fn ($disk) => [$disk => ucfirst($disk)])
                                                            ->toArray();
                                                    })
                                                    ->default('local')
                                                    ->live()
                                                    ->afterStateUpdated(fn ($state, $set) => $set('selected_file', null)),

                                                Forms\Components\Select::make('selected_file')
                                                    ->label('Select File')
                                                    ->options(function ($get) {
                                                        $disk = $get('storage_disk') ?: 'local';
                                                        return $this->getFilesForDisk($disk);
                                                    })
                                                    ->searchable()
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, $get, $set) {
                                                        if ($state && $get('storage_disk')) {
                                                            $set('uploaded_file', null);
                                                            $set('source_detail', json_encode([
                                                                'disk' => $get('storage_disk'),
                                                                'path' => $state,
                                                                'type' => 'existing'
                                                            ]));
                                                        }
                                                    }),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn ($get) => in_array($get('source_type'), ['csv', 'tsv', 'xml', 'wpxml'])),

                            // Database connection options
                            Forms\Components\Group::make([
                                Forms\Components\Select::make('database_connection')
                                    ->label('Database Connection')
                                    ->options(function () {
                                        $connections = array_keys(Config::get('database.connections', []));
                                        $default = Config::get('database.default');
                                        
                                        // Exclude the default connection
                                        $connections = array_filter($connections, fn ($conn) => $conn !== $default);
                                        
                                        return collect($connections)
                                            ->mapWithKeys(fn ($conn) => [$conn => ucfirst($conn)])
                                            ->toArray();
                                    })
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, $set) {
                                        if ($state) {
                                            $set('source_detail', json_encode([
                                                'connection' => $state,
                                                'type' => 'database'
                                            ]));
                                        }
                                    }),
                                
                                Forms\Components\Placeholder::make('database_note')
                                    ->label('')
                                    ->content('Select a database connection to import data from. The default connection is excluded to prevent conflicts.'),
                            ])
                            ->visible(fn ($get) => $get('source_type') === 'database'),

                            Forms\Components\Hidden::make('source_detail'),
                        ]),

                    Wizard\Step::make('Configuration')
                        ->schema([
                            Forms\Components\Select::make('driver')
                                ->label('Import Driver')
                                ->options([
                                    'Crumbls\Importer\Drivers\AutoDriver' => 'Auto-Detect Driver',
                                    'Crumbls\Importer\Drivers\WordPressDriver' => 'WordPress Database Driver',
                                    'Crumbls\Importer\Drivers\WpXmlDriver' => 'WordPress XML Driver',
                                ])
                                ->default('Crumbls\Importer\Drivers\AutoDriver')
                                ->helperText('Auto-detect will analyze your data source and choose the best driver automatically.')
                                ->required(),

                            Forms\Components\TextInput::make('data_limit')
                                ->label('Record Limit')
                                ->numeric()
                                ->helperText('Optional: Limit the number of records to import for testing'),

                            Forms\Components\KeyValue::make('metadata')
                                ->label('Additional Configuration')
                                ->helperText('Optional: Add any additional configuration options')
                                ->addActionLabel('Add Configuration'),
                        ]),
                ])
                ->columnSpanFull()
                ->submitAction(view('filament-panels::pages.actions.create-button-action'))
            ]);
    }

    protected function getFilesForDisk(string $disk): array
    {
        try {
            $storage = Storage::disk($disk);
            $files = $storage->files();
            
            // Filter for supported file types
            $supportedExtensions = ['csv', 'tsv', 'xml'];
            $filteredFiles = array_filter($files, function ($file) use ($supportedExtensions) {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                return in_array(strtolower($extension), $supportedExtensions);
            });

            return collect($filteredFiles)
                ->mapWithKeys(fn ($file) => [$file => $file])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Clean up temporary form fields
        unset($data['uploaded_file'], $data['selected_file'], $data['storage_disk'], $data['database_connection']);
        
        // Set default driver if not specified
        if (empty($data['driver'])) {
            $data['driver'] = AutoDriver::class;
        }

        // Ensure source_detail is properly formatted
        if (!empty($data['source_detail']) && is_string($data['source_detail'])) {
            $sourceDetail = json_decode($data['source_detail'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data['source_detail'] = $sourceDetail;
            }
        }

        return $data;
    }
}