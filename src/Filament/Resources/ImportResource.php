<?php

namespace Crumbls\Importer\Filament\Resources;

use Crumbls\Importer\Filament\Resources\ImportResource\Pages\CreateImport;
use Crumbls\Importer\Filament\Resources\ImportResource\Pages\ListImports;
use Crumbls\Importer\Resolvers\ModelResolver;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
//use Filament\Tables\Actions\BulkActionGroup;
//use Filament\Tables\Actions\DeleteBulkAction;
//use Filament\Tables\Actions\EditAction;
//use Filament\Tables\Actions\ViewAction;
//use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;

use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
class  ImportResource extends Resource
{
//    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

//    protected static ?string $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 1;

    public static function getModel(): string
    {
        return ModelResolver::import();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Import Configuration')
                ->schema([
                    Forms\Components\Select::make('driver')
                        ->label('Driver')
                        ->options([
                            'Crumbls\Importer\Drivers\AutoDriver' => 'Auto Driver',
                            'Crumbls\Importer\Drivers\WordPressDriver' => 'WordPress Driver',
                            'Crumbls\Importer\Drivers\WpXmlDriver' => 'WordPress XML Driver',
                        ])
                        ->default(config('importer.default_driver'))
                        ->required(),

                    Forms\Components\Select::make('source_type')
                        ->label('Source Type')
                        ->options([
                            'file' => 'File',
                            'database' => 'Database',
                            'api' => 'API',
                            'url' => 'URL',
                        ])
                        ->required(),

                    Forms\Components\Textarea::make('source_detail')
                        ->label('Source Detail')
                        ->helperText('JSON configuration for the import source')
                        ->rows(4),

                    Forms\Components\TextInput::make('data_limit')
                        ->label('Data Limit')
                        ->numeric()
                        ->helperText('Maximum number of records to import (optional)'),
                ]),

            Section::make('Filtering & Scoping')
                ->schema([
                    Forms\Components\KeyValue::make('scope_conditions')
                        ->label('Scope Conditions')
                        ->helperText('Simple field-value conditions for filtering data'),

                    Forms\Components\Textarea::make('where_clause')
                        ->label('Where Clause')
                        ->helperText('Raw WHERE conditions for advanced filtering')
                        ->rows(2),
                ]),

            Section::make('Metadata')
                ->schema([
                    Forms\Components\KeyValue::make('metadata')
                        ->label('Metadata')
                        ->helperText('Additional configuration and runtime data'),
                ])
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->color('primary'),

                TextColumn::make('source_type')
                    ->label('Source Type')
                    ->badge()
                    ->color('info'),

                TextColumn::make('state')
                    ->label('Current State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'Completed') => 'success',
                        str_contains($state, 'Failed') || str_contains($state, 'Error') => 'danger',
                        str_contains($state, 'Processing') || str_contains($state, 'Executing') => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('data_limit')
                    ->label('Limit')
                    ->sortable()
                    ->placeholder('No limit'),

                TextColumn::make('user.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->options([
                        'Crumbls\Importer\Drivers\AutoDriver' => 'Auto Driver',
                        'Crumbls\Importer\Drivers\WordPressDriver' => 'WordPress Driver',
                        'Crumbls\Importer\Drivers\WpXmlDriver' => 'WordPress XML Driver',
                    ]),

                SelectFilter::make('source_type')
                    ->options([
                        'file' => 'File',
                        'database' => 'Database',
                        'api' => 'API',
                        'url' => 'URL',
                    ]),

                Tables\Filters\Filter::make('completed')
                    ->query(fn ($query) => $query->whereNotNull('completed_at'))
                    ->label('Completed Imports'),

                Tables\Filters\Filter::make('failed')
                    ->query(fn ($query) => $query->whereNotNull('failed_at'))
                    ->label('Failed Imports'),

                Tables\Filters\Filter::make('in_progress')
                    ->query(fn ($query) => $query->whereNull('completed_at')->whereNull('failed_at'))
                    ->label('In Progress'),
            ])
            ->actions([
                ViewAction::make(),
//                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'create' => CreateImport::route('/create'),
  //          'view' => Pages\ImportResource\ViewImport::route('/{record}'),
    //        'edit' => Pages\ImportResource\EditImport::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}