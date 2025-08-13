<?php

namespace Crumbls\Importer\Filament\Resources\Imports\Schemas;

use Crumbls\Importer\Filament\Components\FileBrowser;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\FileUpload;
//use Filament\Schemas\Components\Textarea;
use Filament\Schemas\Components\Placeholder;
use Crumbls\Importer\Filament\Components\FileBrowserField;

class ImporterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import Source Selection')
                    ->description('Choose how you want to import your data')
                    ->schema([
                        \Filament\Forms\Components\Radio::make('source_type')
                            ->label('Data Source Type')
                            ->options([
                                'file' => 'File Import',
                                'database' => 'Database Connection',
                            ])
                            ->descriptions([
                                'file' => 'Upload a CSV, Excel, or other data file from your computer',
                                'database' => 'Connect to an existing database (MySQL, PostgreSQL, etc.)',
                            ])
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                    ])
	                ->visible(fn ($get) => !filled($get('source_type'))),


                Section::make('Source Details')
                    ->schema([
                        \Filament\Forms\Components\Select::make('storage_disk')
                            ->label('Storage Disk')
                            ->options(function () {
                                $disks = config('filesystems.disks', []);
                                $diskOptions = collect($disks)
                                    ->filter(fn($config) => in_array($config['driver'] ?? '', ['local', 's3', 'ftp', 'sftp']))
                                    ->mapWithKeys(fn($config, $name) => [$name => ucfirst($name)])
                                    ->toArray();
                                
                                // Add "Upload a File" option at the end
                                $diskOptions['upload'] = __('crumbls-importer::forms.upload_file');
                                
                                return $diskOptions;
                            })
                            ->visible(fn ($get) => $get('source_type') === 'file')
                            ->required(fn ($get) => $get('source_type') === 'file')
                            ->live(),
                            
                        \Filament\Forms\Components\FileUpload::make('source_detail_file')
                            ->label(__('crumbls-importer::forms.select_or_upload_file'))
                            ->disk(fn ($get) => $get('storage_disk') === 'upload' ? config('filesystems.default') : $get('storage_disk'))
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/json'])
                            ->visible(fn ($get) => $get('source_type') === 'file' && $get('storage_disk') === 'upload')
                            ->required(fn ($get) => $get('source_type') === 'file' && $get('storage_disk') === 'upload'),

						FileBrowser::make('file_browser')
							->references('storage_disk')
							->label(__('crumbls-importer::forms.browse_files'))
//                            ->view('crumbls-importer::components.file-browser-list')
                            ->viewData(fn ($get) => [
                                'disk' => $get('storage_disk'),
                                'currentPath' => '',
                            ])
                            ->visible(fn ($get) => $get('source_type') === 'file' && filled($get('storage_disk')) && $get('storage_disk') !== 'upload')
	                    ,

                        \Filament\Forms\Components\Hidden::make('selected_file_path')
                            ->default(''),

                        \Filament\Forms\Components\Select::make('source_detail_database')
                            ->label('Database Connection')
                            ->helperText('Select a database connection to import from (WordPress default database excluded)')
                            ->options(function () {
                                $connections = config('database.connections', []);
                                $default = config('database.default');
                                
                                // Remove the default connection and return the rest
                                return collect($connections)
                                    ->except($default)
                                    ->mapWithKeys(fn ($config, $name) => [$name => $name])
                                    ->toArray();
                            })
                            ->visible(fn ($get) => $get('source_type') === 'database')
                            ->required(fn ($get) => $get('source_type') === 'database'),
                    ])
                    ->visible(fn ($get) => filled($get('source_type'))),

            ]);
    }
}
